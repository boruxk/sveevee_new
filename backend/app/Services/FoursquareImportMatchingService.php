<?php

namespace App\Services;

use App\Exceptions\BusinessImportReviewException;
use App\Models\BusinessImportMatchReview;
use App\Models\BusinessImportSource;
use App\Models\BusinessImportSourceAlias;
use App\Models\Page;
use App\Models\PageIdentityKey;

class FoursquareImportMatchingService
{
    public function __construct(private readonly PageIdentityService $identities) {}

    public static function aliasIds(array $metadata): array
    {
        $ids = [];
        foreach (is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [] as $entry) {
            // Laravel normalizes incoming empty strings to null, including this record-level marker.
            if (! is_array($entry) || ! array_key_exists('property', $entry) || ! in_array($entry['property'], ['', null], true)
                || ! is_string($entry['dataset'] ?? null) || strcasecmp($entry['dataset'], 'Foursquare') !== 0
                || (isset($entry['provider']) && (! is_string($entry['provider']) || strcasecmp($entry['provider'], 'foursquare') !== 0))
                || ! is_string($entry['record_id'] ?? null)
                || preg_match('/^[a-f0-9]{24}$/D', $entry['record_id']) !== 1) {
                continue;
            }
            $ids[$entry['record_id']] = true;
        }

        return array_keys($ids);
    }

    public function syncAliases(BusinessImportSource $association): void
    {
        if ($association->provider !== 'overture_places') {
            return;
        }
        $ids = self::aliasIds($association->metadata);
        BusinessImportSourceAlias::where('business_import_source_id', $association->id)
            ->whereNotIn('source_id', $ids)->delete();
        foreach ($ids as $id) {
            BusinessImportSourceAlias::firstOrCreate([
                'provider' => 'foursquare_places', 'source_id' => $id, 'business_import_source_id' => $association->id,
            ]);
        }
    }

    /** Resolve a new Foursquare ID without treating shared contacts as proof of identity. */
    public function resolve(array $input, array $source): ?array
    {
        $aliases = BusinessImportSourceAlias::query()->where('provider', 'foursquare_places')
            ->where('source_id', $source['id'])->with('association.page')->limit(101)->get();
        if ($aliases->isNotEmpty()) {
            $pages = $aliases->map(fn (BusinessImportSourceAlias $alias) => $alias->association?->page);
            $candidates = $pages->filter()->unique('id')->map(fn (Page $page): array => $this->match($page, ['source_alias']))->values()->all();
            if ($aliases->count() > 100 || $pages->contains(null) || count($candidates) !== 1) {
                throw new BusinessImportReviewException($source, $input, $candidates, 'ambiguous_source_alias');
            }
            $page = $pages->first();
            if ($page->type !== Page::TYPE_BUSINESS
                || $this->identities->importAddressesConflict($input['address'] ?? [], $page->setup['address'] ?? [])) {
                throw new BusinessImportReviewException($source, $input, $candidates, 'source_alias_location_conflict');
            }

            return $this->assertRequestedPage($input, $source, $candidates[0]);
        }
        $matches = $this->identities->exactMatches(
            [...$input, 'type' => Page::TYPE_BUSINESS],
            allowSingleContactSignal: true, separateLocations: true, confirmedLocationsOnly: true
        )->all();
        if (count($matches) > 1) {
            throw new BusinessImportReviewException($source, $input, $matches, 'ambiguous_confirmed_location');
        }
        if ($matches !== []) {
            return $this->assertRequestedPage($input, $source, $matches[0]);
        }
        $identity = $this->identities->fromInput([...$input, 'type' => Page::TYPE_BUSINESS]);
        $candidates = PageIdentityKey::query()->where('type', Page::TYPE_BUSINESS)->with('page')
            ->where(function ($query) use ($identity): void {
                $query->whereRaw('1 = 0');
                foreach (['normalized_phone', 'normalized_email', 'normalized_website', 'import_name_city_hash'] as $field) {
                    if (filled($identity[$field])) {
                        $query->orWhere($field, $identity[$field]);
                    }
                }
                if ($identity['normalized_name'] !== '') {
                    $query->orWhere('normalized_name', $identity['normalized_name']);
                }
            })
            ->lazyById(100)
            ->filter(fn (PageIdentityKey $key): bool => $key->page !== null
                && ! $this->clearlyDifferentLocation($input['address'] ?? [], $key->page->setup['address'] ?? []))
            ->take(100)
            ->map(fn (PageIdentityKey $key): array => $this->match($key->page, ['unconfirmed_identity']))
            ->values()->all();
        if ($candidates !== [] || filled($input['id'] ?? null)) {
            throw new BusinessImportReviewException($source, $input, $candidates, 'unconfirmed_identity');
        }

        return null;
    }

    public function recordReview(BusinessImportReviewException $exception): BusinessImportMatchReview
    {
        BusinessImportMatchReview::upsert([[
            'provider' => $exception->source['provider'], 'source_id' => $exception->source['id'],
            'status' => 'pending', 'reason' => $exception->reviewReason,
            'payload' => json_encode([...$exception->input, 'source' => $exception->source], JSON_THROW_ON_ERROR),
            'candidates' => json_encode($exception->candidates, JSON_THROW_ON_ERROR),
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]], ['provider', 'source_id'], ['reason', 'payload', 'candidates', 'last_seen_at']);

        return BusinessImportMatchReview::where('provider', $exception->source['provider'])
            ->where('source_id', $exception->source['id'])->sole();
    }

    private function assertRequestedPage(array $input, array $source, array $match): array
    {
        if (filled($input['id'] ?? null) && (int) $input['id'] !== $match['id']) {
            throw new BusinessImportReviewException($source, $input, [$match], 'requested_page_conflict');
        }

        return $match;
    }

    private function clearlyDifferentLocation(array $left, array $right): bool
    {
        // Different spellings or scripts are not evidence that two addresses are different.
        // Distinct house numbers on the same named street and city do establish separate branches.
        $leftCityText = $this->identities->text($left['city'] ?? '');
        if ($leftCityText === '' || $leftCityText !== $this->identities->text($right['city'] ?? '')) {
            return false;
        }
        $parts = function (array $address): array {
            $street = trim((string) ($address['street'] ?? ''));
            $number = $this->identities->text($address['number'] ?? '');
            if (preg_match('/\s+([0-9]+[\p{L}]?)\s*$/u', $street, $match) === 1) {
                $number = $number !== '' ? $number : $this->identities->text($match[1]);
                $street = substr($street, 0, -strlen($match[0]));
            }

            return [$this->identities->text($street), $number];
        };
        [$leftStreet, $leftNumber] = $parts($left);
        [$rightStreet, $rightNumber] = $parts($right);

        return $leftStreet !== '' && $leftStreet === $rightStreet
            && $leftNumber !== '' && $rightNumber !== '' && $leftNumber !== $rightNumber;
    }

    private function match(Page $page, array $signals): array
    {
        return [
            'id' => $page->id, 'name' => $page->name, 'type' => $page->type, 'category_key' => $page->category_key,
            'public_path' => $page->public_path, 'matched_on' => $signals, 'address' => $page->setup['address'] ?? [],
        ];
    }
}
