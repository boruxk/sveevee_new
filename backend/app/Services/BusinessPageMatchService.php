<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageIdentityKey;
use App\Support\CatalogTopics;
use Illuminate\Support\Collection;

class BusinessPageMatchService
{
    public function __construct(
        private readonly PageIdentityService $identities,
        private readonly ImportSourceCatalogService $catalog,
    ) {}

    /**
     * Same name AND at least one other supplied business detail.
     * The identity index is maintained by PageObserver; never scan/backfill the full catalog here.
     * Call inside the save transaction so each candidate's ownership and data are checked under lock.
     *
     * @return Collection<int, array{page: Page, matched_on: list<string>}>
     */
    public function matches(array $data): Collection
    {
        $name = $this->identities->text($data['name'] ?? null);
        if ($name === '') {
            return collect();
        }

        $input = $this->signals($data);
        $matches = collect();
        $candidates = PageIdentityKey::query()
            ->where('type', Page::TYPE_BUSINESS)
            ->where('normalized_name', $name)
            ->select(['id', 'page_id'])
            ->lazyById(100);

        foreach ($candidates as $candidate) {
            $page = Page::query()->where('type', Page::TYPE_BUSINESS)
                ->lockForUpdate()->find($candidate->page_id);
            if ($page === null || $this->identities->text($page->name) !== $name) {
                continue;
            }
            $existing = $this->signals($page->getAttributes(), $page->setup ?? []);
            $matched = ['name'];
            foreach ($input as $field => $values) {
                if ($values !== [] && array_intersect($values, $existing[$field] ?? []) !== []) {
                    $matched[] = $field;
                }
            }
            if (count($matched) > 1) {
                $matches->push(['page' => $page, 'matched_on' => $matched]);
            }
        }

        return $matches;
    }

    private function signals(array $data, ?array $existingSetup = null): array
    {
        $setup = $existingSetup ?? (is_array($data['setup'] ?? null) ? $data['setup'] : []);
        $contact = is_array($setup['contact'] ?? null) ? $setup['contact'] : [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];
        $socials = is_array($setup['socials'] ?? null) ? $setup['socials'] : [];
        $category = CatalogTopics::canonicalKeyForScope(
            (string) ($data['category_key'] ?? ''), CatalogTopics::SCOPE_BUSINESS_PAGES
        ) ?? ($data['category_key'] ?? null);
        $city = is_string($address['city'] ?? null) ? $address['city'] : null;
        $fullAddress = filled($address['street'] ?? null)
            ? implode(' ', array_filter([
                $address['street'], $address['number'] ?? null,
                $address['neighborhood'] ?? null, $address['city'] ?? null,
            ], fn ($value) => filled($value))) : null;
        $signals = [
            'phone' => [$this->identities->phone($data['phone'] ?? null), $this->identities->phone($contact['tel'] ?? null)],
            'email' => [$this->identities->email($data['contact_email'] ?? null), $this->identities->email($contact['email'] ?? null)],
            'website' => [$this->url($setup['website'] ?? null)],
            'city' => [$this->identities->text($this->catalog->knownCity($city) ?? $city)],
            'neighborhood' => [$this->identities->text($address['neighborhood'] ?? null)],
            'address' => [$this->identities->text($data['address'] ?? null), $this->identities->text($fullAddress)],
            'category' => [$category],
            'whatsapp' => [$this->identities->phone($contact['whatsapp'] ?? null)],
        ];
        foreach (['facebook', 'instagram', 'tiktok', 'x', 'telegram'] as $field) {
            $signals[$field] = [$this->url($socials[$field] ?? null)];
        }

        return array_map(fn (array $values): array => array_values(array_unique(array_filter(
            $values, fn ($value): bool => is_string($value) && $value !== ''
        ))), $signals);
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $base = $this->identities->fromInput(['website' => $value])['normalized_website'];
        if ($base === null) {
            return null;
        }
        $url = preg_match('~^https?://~i', $value) ? $value : 'https://'.$value;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        // A Facebook profile.php?id=... identifies a particular business. Strip
        // tracking only, otherwise different profiles would count as the same link.
        foreach (array_keys($query) as $key) {
            $normalized = strtolower((string) $key);
            if (str_starts_with($normalized, 'utm_') || in_array($normalized, ['fbclid', 'gclid', 'msclkid'], true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return $base.($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
