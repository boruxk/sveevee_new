<?php

namespace App\Services;

use App\Exceptions\BusinessImportException;
use App\Exceptions\BusinessImportReviewException;
use App\Exceptions\ExactPageDuplicateException;
use App\Models\BusinessImportPage;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use App\Rules\CleanContent;
use App\Support\CatalogTopics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessImportService
{
    private const GOV_COMPANIES_RESOURCE = 'f004176c-b85f-4542-8901-7b3176f9a054';

    private const GOV_BEER_SHEVA_RESOURCE = '7d4c61e2-2416-453e-8efb-bd02ec89db35';

    private const TOP_LEVEL_FIELDS = [
        'id',
        'type',
        'name',
        'public_description',
        'contact_email',
        'phone',
        'whatsapp',
        'website',
        'category_key',
        'palette_key',
        'address',
        'socials',
        'opening_hours',
        'service_areas',
        'specialties',
        'source',
    ];

    private const ADDRESS_FIELDS = ['street', 'number', 'city', 'neighborhood'];

    private const SOCIAL_FIELDS = ['facebook', 'instagram', 'tiktok', 'telegram', 'x'];

    public function __construct(
        private readonly AiWorkPageService $pages,
        private readonly PageIdentityService $identities,
        private readonly ImportSourceCatalogService $sourceCatalog,
        private readonly FoursquareImportMatchingService $foursquare,
    ) {}

    public function search(array $input): array
    {
        $filters = Validator::make($input, [
            'id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'category_key' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120', 'required_with:neighborhood'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $categoryKey = null;
        if (filled($filters['category_key'] ?? null)) {
            $categoryKey = CatalogTopics::canonicalKeyForScope(
                (string) $filters['category_key'],
                CatalogTopics::SCOPE_BUSINESS_PAGES
            );
            if (! $categoryKey) {
                throw ValidationException::withMessages(['category_key' => ['Unknown business category.']]);
            }
        }

        [$city, $neighborhood] = $this->canonicalLocation(
            $filters['city'] ?? null,
            $filters['neighborhood'] ?? null
        );
        $this->identities->ensureAll();

        $query = Page::query()
            ->where('type', Page::TYPE_BUSINESS)
            ->when(filled($filters['id'] ?? null), fn ($query) => $query->whereKey((int) $filters['id']))
            ->with('identityKey')
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $like = $this->like((string) $filters['q']);
                $query->where(function ($search) use ($like): void {
                    $search->where('name', 'like', $like)
                        ->orWhere('contact_email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('address', 'like', $like);
                });
            })
            ->when(filled($filters['name'] ?? null), fn ($query) => $query->where('name', 'like', $this->like((string) $filters['name'])))
            ->when($categoryKey, fn ($query) => $query->where('category_key', $categoryKey))
            ->when(filled($filters['phone'] ?? null), function ($query) use ($filters): void {
                $phone = $this->identities->phone($filters['phone']);
                $query->whereHas('identityKey', fn ($identity) => $identity->where('normalized_phone', $phone));
            })
            ->when(filled($filters['contact_email'] ?? null), function ($query) use ($filters): void {
                $email = $this->identities->email($filters['contact_email']);
                $query->whereHas('identityKey', fn ($identity) => $identity->where('normalized_email', $email));
            })
            ->when($city, function ($query) use ($city): void {
                $normalized = $this->identities->text($city);
                $query->whereHas('identityKey', fn ($identity) => $identity->where('normalized_city', $normalized));
            })
            ->when($neighborhood, function ($query) use ($neighborhood): void {
                $normalized = $this->identities->text($neighborhood);
                $query->whereHas('identityKey', fn ($identity) => $identity->where('normalized_neighborhood', $normalized));
            })
            ->latest('updated_at');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'businesses' => collect($paginator->items())
                ->map(fn (Page $page): array => $this->payload($page))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function duplicateMatches(array $input, ?int $excludePageId = null): array
    {
        $this->assertKnownFields($input);
        $this->assertBusinessType($input);
        $source = $this->sourceDescriptor($input);
        if ($source !== null) {
            $input = $this->withoutInvalidSourceWebsite($input);
        }
        $prepared = [
            ...$input,
            'type' => Page::TYPE_BUSINESS,
            'name' => $source !== null && ! is_string($input['name'] ?? '')
                ? $input['name']
                : trim((string) ($input['name'] ?? '')),
            'contact_email' => $input['contact_email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'address' => is_array($input['address'] ?? null) ? $input['address'] : [],
        ];

        $data = Validator::make($prepared, [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS])],
            'name' => ['nullable', 'string', 'max:255', ...($source === null ? [new CleanContent] : [])],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:2048'],
            'category_key' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.number' => ['nullable', 'string', 'max:40'],
            'address.city' => ['nullable', 'string', 'max:120'],
            'address.neighborhood' => ['nullable', 'string', 'max:120'],
        ])->validate();

        if ($source !== null) {
            $mapping = $this->sourceQuery($source)->first();
            if ($mapping !== null) {
                $page = $mapping->page;
                if ($page === null) {
                    throw new BusinessImportException('The source was associated with a page that no longer exists.', 409, 'source_conflict');
                }
                $this->assertSourceAddress($data, $page);
                $this->assertSourceRecordIdentity($input, $page, $source);
                if (filled($input['id'] ?? null) && (int) $input['id'] !== $page->id) {
                    throw new BusinessImportException('The source ID already belongs to another page.', 409, 'source_conflict');
                }

                return $page->id === $excludePageId ? [] : [[
                    'id' => $page->id,
                    'name' => $page->name,
                    'type' => $page->type,
                    'category_key' => $page->category_key,
                    'public_path' => $page->public_path,
                    'matched_on' => ['source_id'],
                    'address' => $page->setup['address'] ?? [],
                ]];
            }

            if ($source['provider'] === 'foursquare_places') {
                $match = $this->foursquare->resolve([...$input, ...$data], $source);

                return $match === null || $match['id'] === $excludePageId ? [] : [$match];
            }

            return $this->identities->exactMatches($data, $excludePageId, allowSingleContactSignal: true, separateLocations: true, confirmedLocationsOnly: true)->all();
        }

        if (! filled($data['name'] ?? null)
            && ! filled($data['phone'] ?? null)
            && ! filled($data['contact_email'] ?? null)) {
            throw ValidationException::withMessages([
                'identity' => ['Provide at least one of name, phone, or contact_email.'],
            ]);
        }

        return $this->identities->exactMatches($data, $excludePageId, allowSingleContactSignal: true, separateLocations: true)->all();
    }

    public function upsert(string $clientId, array $input): array
    {
        $this->assertKnownFields($input);
        $id = Validator::make(['id' => $input['id'] ?? null], [
            'id' => ['nullable', 'integer', 'min:1'],
        ])->validate()['id'] ?? null;

        if (($source = $this->sourceDescriptor($input)) !== null) {
            return $this->upsertSource($clientId, $input, $source);
        }

        if ($id) {
            $page = Page::query()->find($id);
            if (! $page) {
                throw new BusinessImportException('Business page not found.', 404, 'not_found');
            }

            return [
                'operation' => 'updated',
                'business' => $this->update($clientId, $page, $input),
            ];
        }

        return [
            'operation' => 'created',
            'business' => $this->create($clientId, $input),
        ];
    }

    public function create(string $clientId, array $input): array
    {
        $this->assertKnownFields($input);
        $this->assertBusinessType($input);
        unset($input['id']);
        $input['type'] = Page::TYPE_BUSINESS;

        if (! filled($input['palette_key'] ?? null)) {
            $input['palette_key'] = $this->pages->automaticPalette($input);
        }

        $data = $this->pages->validate($input);
        $worker = $this->creator();

        $page = DB::transaction(function () use ($clientId, $worker, $data): Page {
            $page = $this->pages->create($worker, $data, allowSingleContactDuplicate: true, separateLocations: true);
            BusinessImportPage::query()->create([
                'page_id' => $page->id,
                'created_by_oauth_client_id' => $clientId,
                'last_updated_by_oauth_client_id' => $clientId,
                'last_payload_hash' => $this->payloadHash($data),
            ]);

            return $page;
        });

        return $this->payload($page->fresh());
    }

    public function update(string $clientId, Page $page, array $input): array
    {
        $this->assertKnownFields($input);
        $this->assertBusinessType($input);
        $this->assertEditable($page);

        if (isset($input['id']) && (int) $input['id'] !== $page->id) {
            throw ValidationException::withMessages(['id' => ['The body id must match the URL id.']]);
        }

        if (($source = $this->sourceDescriptor($input)) !== null) {
            return $this->upsertSource($clientId, [...$input, 'id' => $page->id], $source)['business'];
        }

        unset($input['id'], $input['type']);
        if (array_intersect(array_keys($input), array_diff(self::TOP_LEVEL_FIELDS, ['id', 'type'])) === []) {
            throw ValidationException::withMessages(['business' => ['Provide at least one field to update.']]);
        }

        $merged = $this->mergeWithExisting($page, $input);
        $data = $this->pages->validate($merged);

        $page = DB::transaction(function () use ($clientId, $page, $data): Page {
            $page = $this->pages->update($page, $data, allowSingleContactDuplicate: true, separateLocations: true);
            $tracking = BusinessImportPage::query()->firstOrNew(['page_id' => $page->id]);
            $tracking->fill([
                'last_updated_by_oauth_client_id' => $clientId,
                'last_payload_hash' => $this->payloadHash($data),
            ]);
            $tracking->save();

            return $page;
        });

        return $this->payload($page->fresh());
    }

    public function payloadHash(mixed $payload): string
    {
        return hash('sha256', (string) json_encode(
            $this->sortForHash($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function sourceDescriptor(array $input): ?array
    {
        if (! array_key_exists('source', $input)) {
            return null;
        }
        $source = Validator::make($input, [
            'source' => ['required', 'array:provider,id,url,metadata'],
            'source.provider' => ['required', Rule::in(['overture_places', 'data_gov_ckan', 'tel_aviv_business_licenses', 'foursquare_places'])],
            'source.id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'source.url' => ['required', 'url:https', 'max:2048'],
            'source.metadata' => ['present', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && $value !== [] && array_is_list($value)) {
                    $fail('The source metadata must be an object.');
                }
            }],
        ])->validate()['source'];
        $parts = parse_url($source['url']);
        parse_str($parts['query'] ?? '', $query);
        if ($source['provider'] === 'foursquare_places') {
            if (preg_match('/^[a-f0-9]{24}$/D', $source['id']) !== 1) {
                throw ValidationException::withMessages(['source.id' => ['Use the 24-character lowercase Foursquare place ID.']]);
            }
            if (! $this->sourceEndpointMatches($parts, 'foursquare.com', '/placemakers/review-place/'.$source['id']) || isset($parts['query'])) {
                throw ValidationException::withMessages(['source.url' => ['Use the official Foursquare Placemaker URL for this source ID.']]);
            }
            foreach (['fsq_place_id', 'source_id'] as $field) {
                if (isset($source['metadata'][$field]) && $source['metadata'][$field] !== $source['id']) {
                    throw ValidationException::withMessages(['source.metadata.'.$field => ['The metadata source ID must match source.id.']]);
                }
            }

            return $source;
        }
        if ($source['provider'] !== 'overture_places') {
            $valid = $source['provider'] === 'data_gov_ckan'
                ? $this->validGovSourceUrl($source, $parts, $query)
                : $this->validTelAvivSourceUrl($source, $parts, $query);
            if (! $valid) {
                throw ValidationException::withMessages(['source.url' => ['Use the official record URL for this source ID.']]);
            }

            return $source;
        }
        if (strtolower($parts['host'] ?? '') !== 'explore.overturemaps.org'
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || (($query['gers'] ?? null) !== $source['id'] && ($query['feature'] ?? null) !== 'places.place.'.$source['id'])
            || (isset($query['gers']) && $query['gers'] !== $source['id'])
            || (isset($query['feature']) && $query['feature'] !== 'places.place.'.$source['id'])) {
            throw ValidationException::withMessages(['source.url' => ['Use the Overture Explorer URL for this source ID.']]);
        }

        return $source;
    }

    private function validGovSourceUrl(array $source, array $parts, array $query): bool
    {
        if (! $this->sourceEndpointMatches($parts, 'data.gov.il', '/api/3/action/datastore_search')
            || ! $this->sourceQueryHasExactKeys($parts, ['resource_id', 'limit', 'filters'])
            || ($query['limit'] ?? null) !== '1'
            || ! is_string($query['resource_id'] ?? null) || ! is_string($query['filters'] ?? null)) {
            return false;
        }
        $field = match ($query['resource_id']) {
            self::GOV_COMPANIES_RESOURCE => 'מספר חברה',
            self::GOV_BEER_SHEVA_RESOURCE => '_id',
            default => null,
        };
        $filters = json_decode($query['filters'], true);
        if ($field === null || ! is_array($filters) || array_keys($filters) !== [$field]
            || ! is_int($filters[$field]) || $filters[$field] < 1) {
            return false;
        }
        $recordId = (string) $filters[$field];

        return ($field !== 'מספר חברה' || preg_match('/^[1-9][0-9]{8}$/D', $recordId) === 1)
            && $source['id'] === $query['resource_id'].':'.$recordId;
    }

    private function validTelAvivSourceUrl(array $source, array $parts, array $query): bool
    {
        if (! $this->sourceEndpointMatches($parts, 'gisn.tel-aviv.gov.il', '/arcgis/rest/services/IView2/MapServer/964/query')
            || ! $this->sourceQueryHasExactKeys($parts, ['where', 'outFields', 'returnGeometry', 'f'])
            || ($query['outFields'] ?? null) !== '*' || ($query['returnGeometry'] ?? null) !== 'false'
            || ($query['f'] ?? null) !== 'json' || ! is_string($query['where'] ?? null)) {
            return false;
        }
        $where = $query['where'];

        return preg_match("/^ms_esek_rashi=[1-9][0-9]* AND ms_esek_mishne=(?:0|[1-9][0-9]*) AND mahuiot='(?:[^'\\x00-\\x1F\\x7F]|'')*'$/Du", $where) === 1
            && hash_equals('964:'.hash('sha256', $where), $source['id']);
    }

    private function sourceEndpointMatches(array $parts, string $host, string $path): bool
    {
        return strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === $host && ($parts['path'] ?? '') === $path
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['fragment']);
    }

    private function sourceQueryHasExactKeys(array $parts, array $expected): bool
    {
        $keys = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            $key = rawurldecode(explode('=', $pair, 2)[0]);
            if (! in_array($key, $expected, true) || isset($keys[$key])) {
                return false;
            }
            $keys[$key] = true;
        }

        return count($keys) === count($expected);
    }

    private function assertSourceRecordIdentity(array $input, Page $page, array $source): void
    {
        if ($source['provider'] !== 'data_gov_ckan' || ! str_starts_with($source['id'], self::GOV_BEER_SHEVA_RESOURCE.':')) {
            return;
        }
        // A CKAN row number can be reassigned. Even a matching address cannot establish
        // that the new record still describes the original business occupying it.
        $names = [];
        if (array_key_exists('name', $input)) {
            $names[] = $input['name'];
        }
        if (array_key_exists('original_name', $source['metadata'])) {
            $names[] = $source['metadata']['original_name'];
        }
        if ($names === [] || collect($names)->contains(fn ($name): bool => ! is_string($name) || ! $this->identities->importNamesMatch($name, $page->name))) {
            throw new BusinessImportException('The source row no longer identifies the same business.', 409, 'source_conflict');
        }
    }

    private function sourceQuery(array $source)
    {
        return BusinessImportSource::query()->where('provider', $source['provider'])->where('source_id', $source['id']);
    }

    private function assertSourceAddress(array $input, Page $page): void
    {
        $existingAddress = (array) ($page->setup['address'] ?? []);
        $mergedAddress = array_replace($existingAddress, (array) ($input['address'] ?? []));
        if ($page->type !== Page::TYPE_BUSINESS
            || $this->identities->importAddressesConflict($mergedAddress, $existingAddress)) {
            throw new BusinessImportException('The source location conflicts with its existing business page.', 409, 'source_conflict');
        }
    }

    private function assertSourceEditable(Page $page): void
    {
        $this->assertEditable($page);
        if (($page->created_by_user_id !== null && $page->created_by_user_id !== $page->user_id)
            || ! $page->user?->hasRole('ai_worker')) {
            throw new BusinessImportException('Transferred business pages cannot be changed by the import API.', 409, 'claimed');
        }
    }

    private function assertSourceAssociation(string $clientId, Page $page, array $input, array $source): void
    {
        $this->assertSourceAddress($input, $page);
        $this->assertSourceRecordIdentity($input, $page, $source);
        if (! $this->identities->importNamesMatch($input['name'] ?? '', $page->name)) {
            throw new BusinessImportException('A source can only be attached to the matching business.', 409, 'source_conflict');
        }
        $confirmed = $this->identities->exactMatches($input, allowSingleContactSignal: true, separateLocations: true, confirmedLocationsOnly: true)
            ->contains(fn (array $match): bool => $match['id'] === $page->id);
        $sameClient = BusinessImportPage::query()->where('page_id', $page->id)->where('created_by_oauth_client_id', $clientId)->exists();
        $hasOtherSource = BusinessImportSource::query()->where('page_id', $page->id)->where('provider', $source['provider'])->exists();
        if (! $confirmed && (! $sameClient || $hasOtherSource)) {
            throw new BusinessImportException('This source cannot be safely associated with the requested page.', 409, 'source_conflict');
        }
    }

    private function upsertSource(string $clientId, array $input, array $source): array
    {
        $this->assertBusinessType($input);
        $input = $this->withoutInvalidSourceWebsite($input);
        if ($source['provider'] === 'foursquare_places') {
            Validator::make($input, [
                'name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'address' => ['sometimes', 'nullable', 'array'],
                'address.street' => ['nullable', 'string', 'max:255'],
                'address.number' => ['nullable', 'string', 'max:40'],
                'address.city' => ['nullable', 'string', 'max:120'],
                'address.neighborhood' => ['nullable', 'string', 'max:120'],
            ])->validate();
        }
        $key = 'business-import-source:'.hash('sha256', $source['provider'].'|'.$source['id']);

        try {
            return Cache::lock($key, 30)->block(10, fn (): array => DB::transaction(function () use ($clientId, $input, $source): array {
                $mapping = $this->sourceQuery($source)->lockForUpdate()->first();
                $requestedId = (int) ($input['id'] ?? 0);
                if ($mapping !== null && ($mapping->page_id === null || ($requestedId > 0 && $requestedId !== $mapping->page_id))) {
                    throw new BusinessImportException('The source ID cannot be moved to another page.', 409, 'source_conflict');
                }
                $foursquareMatch = $source['provider'] === 'foursquare_places' && $mapping === null
                    ? $this->foursquare->resolve($input, $source) : null;
                $pageId = $mapping?->page_id ?? ($foursquareMatch['id'] ?? ($requestedId ?: null));
                $page = $pageId === null ? null : Page::query()->lockForUpdate()->find($pageId);
                if ($pageId !== null && $page === null) {
                    throw new BusinessImportException('Business page not found.', 404, 'not_found');
                }
                unset($input['source'], $input['id']);
                $input['type'] = Page::TYPE_BUSINESS;
                $operation = 'updated';
                if ($page !== null) {
                    $this->assertSourceEditable($page);
                    $this->assertSourceAddress($input, $page);
                    $this->assertSourceRecordIdentity($input, $page, $source);
                    $patch = $source['provider'] === 'foursquare_places' ? $this->missingSourceContacts($page, $input) : $input;
                    $data = $this->pages->validate($this->mergeWithExisting($page, $patch), allowSourcePlace: true);
                    if ($mapping === null && $foursquareMatch === null) {
                        $this->assertSourceAssociation($clientId, $page, $data, $source);
                    }
                    $page = $this->pages->update($page, $data, allowSingleContactDuplicate: true, separateLocations: true, confirmedLocationsOnly: true);
                } else {
                    $data = $this->pages->validate($input, allowSourcePlace: true);
                    $data['palette_key'] = $this->pages->automaticPalette($input, $input['palette_key'] ?? null);
                    try {
                        $page = $this->pages->create($this->creator(), $data, allowSingleContactDuplicate: true, separateLocations: true, confirmedLocationsOnly: true);
                        $operation = 'created';
                    } catch (ExactPageDuplicateException $exception) {
                        if (count($exception->matches) !== 1) {
                            throw $exception;
                        }
                        $page = Page::query()->lockForUpdate()->findOrFail($exception->matches[0]['id']);
                        $this->assertSourceEditable($page);
                        $this->assertSourceAssociation($clientId, $page, $data, $source);
                        // Register confirmed existing pages without replacing their richer public details.
                        if ($source['provider'] === 'foursquare_places') {
                            $data = $this->pages->validate($this->mergeWithExisting($page, $this->missingSourceContacts($page, $input)), allowSourcePlace: true);
                            $page = $this->pages->update($page, $data, allowSingleContactDuplicate: true, separateLocations: true, confirmedLocationsOnly: true);
                        }
                    }
                }
                $tracking = BusinessImportPage::query()->firstOrNew(['page_id' => $page->id]);
                if ($operation === 'created') {
                    $tracking->created_by_oauth_client_id = $clientId;
                }
                $tracking->fill(['last_updated_by_oauth_client_id' => $clientId, 'last_payload_hash' => $this->payloadHash($data)])->save();
                $mapping ??= new BusinessImportSource;
                $mapping->fill([
                    'provider' => $source['provider'], 'source_id' => $source['id'], 'page_id' => $page->id,
                    'url' => $source['url'], 'metadata' => $source['metadata'],
                ])->save();
                $this->foursquare->syncAliases($mapping);
                $this->sourceCatalog->record($page, $source);

                return ['operation' => $operation, 'business' => $this->payload($page->fresh())];
            }, 3));
        } catch (ExactPageDuplicateException $exception) {
            if ($source['provider'] === 'foursquare_places') {
                throw new BusinessImportReviewException($source, $input, $exception->matches, 'ambiguous_confirmed_location');
            }

            throw $exception;
        }
    }

    public function recordMatchReview(BusinessImportReviewException $exception): array
    {
        $review = $this->foursquare->recordReview($exception);

        return ['review_id' => $review->id, 'reason' => $review->reason];
    }

    private function missingSourceContacts(Page $page, array $input): array
    {
        $current = $this->pages->editableData($page);
        $patch = [];
        foreach (['contact_email', 'phone', 'whatsapp', 'website'] as $field) {
            if (! filled($current[$field] ?? null) && filled($input[$field] ?? null)) {
                $patch[$field] = $input[$field];
            }
        }
        foreach (self::SOCIAL_FIELDS as $field) {
            if (! filled($current['socials'][$field] ?? null) && filled($input['socials'][$field] ?? null)) {
                $patch['socials'][$field] = $input['socials'][$field];
            }
        }

        return $patch;
    }

    private function withoutInvalidSourceWebsite(array $input): array
    {
        if (Validator::make($input, ['website' => ['nullable', 'string', 'url:http,https', 'max:2048']])->fails()) {
            // The worker retains the original value in source metadata. Omission also preserves
            // an existing page's website when the incoming source value is malformed.
            unset($input['website']);
        }

        return $input;
    }

    private function payload(Page $page): array
    {
        $data = $this->pages->editableData($page);
        $socials = is_array($data['socials'] ?? null) ? $data['socials'] : [];

        return [
            'id' => $page->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => $data['name'],
            'public_description' => $data['public_description'],
            'contact_email' => $data['contact_email'],
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'],
            'website' => $data['website'],
            'category_key' => $data['category_key'],
            'address' => $data['address'],
            'socials' => [
                'facebook' => $socials['facebook'] ?? null,
                'instagram' => $socials['instagram'] ?? null,
                'tiktok' => $socials['tiktok'] ?? null,
                'telegram' => $socials['telegram'] ?? null,
                'x' => $socials['x'] ?? null,
            ],
            'opening_hours' => $data['opening_hours'],
            'service_areas' => $data['service_areas'],
            'specialties' => $data['specialties'],
            'is_unclaimed' => $page->is_unclaimed,
            'can_update' => $page->is_unclaimed,
            'public_path' => $page->public_path,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    private function mergeWithExisting(Page $page, array $patch): array
    {
        $merged = $this->pages->editableData($page);
        $merged['type'] = Page::TYPE_BUSINESS;
        $merged['palette_key'] = $page->palette_key;

        foreach (['name', 'public_description', 'contact_email', 'phone', 'whatsapp', 'website', 'category_key', 'palette_key', 'opening_hours', 'service_areas', 'specialties'] as $field) {
            if (array_key_exists($field, $patch)) {
                $merged[$field] = $patch[$field];
            }
        }

        foreach (['address' => self::ADDRESS_FIELDS, 'socials' => self::SOCIAL_FIELDS] as $field => $allowedFields) {
            if (! array_key_exists($field, $patch)) {
                continue;
            }
            if (! is_array($patch[$field])) {
                $merged[$field] = $patch[$field];

                continue;
            }

            $current = is_array($merged[$field] ?? null) ? $merged[$field] : [];
            foreach ($allowedFields as $nestedField) {
                if (array_key_exists($nestedField, $patch[$field])) {
                    $current[$nestedField] = $patch[$field][$nestedField];
                }
            }
            $merged[$field] = $current;
        }

        return $merged;
    }

    private function assertEditable(Page $page): void
    {
        if ($page->type !== Page::TYPE_BUSINESS) {
            throw new BusinessImportException('Business page not found.', 404, 'not_found');
        }
        if (! $page->is_unclaimed) {
            throw new BusinessImportException(
                'Claimed business pages cannot be changed by the import API.',
                409,
                'claimed'
            );
        }
    }

    private function assertBusinessType(array $input): void
    {
        if (array_key_exists('type', $input) && $input['type'] !== Page::TYPE_BUSINESS) {
            throw ValidationException::withMessages(['type' => ['The type must be business.']]);
        }
    }

    private function assertKnownFields(array $input): void
    {
        $errors = [];
        foreach (array_diff(array_keys($input), self::TOP_LEVEL_FIELDS) as $field) {
            $errors[$field] = ['Unsupported field.'];
        }

        foreach (['address' => self::ADDRESS_FIELDS, 'socials' => self::SOCIAL_FIELDS] as $field => $allowedFields) {
            if (! is_array($input[$field] ?? null)) {
                continue;
            }
            foreach (array_diff(array_keys($input[$field]), $allowedFields) as $nestedField) {
                $errors[$field.'.'.$nestedField] = ['Unsupported field.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function creator(): User
    {
        $worker = User::query()
            ->where('role', 'ai_worker')
            ->where('login', config('business_import.creator_login'))
            ->first();

        if (! $worker) {
            throw new BusinessImportException(
                'The business import service account is unavailable.',
                503,
                'service_unavailable'
            );
        }

        return $worker;
    }

    private function canonicalLocation(mixed $cityValue, mixed $neighborhoodValue): array
    {
        $cities = collect(config('locations.cities', []));
        $city = null;
        $neighborhood = null;

        if (filled($cityValue)) {
            $needle = $this->identities->text($cityValue);
            $cityData = $cities->first(fn (array $candidate): bool => $this->identities->text($candidate['name'] ?? '') === $needle);
            if (! $cityData) {
                throw ValidationException::withMessages(['city' => ['Unknown city.']]);
            }
            $city = $cityData['name'];

            if (filled($neighborhoodValue)) {
                $neighborhoodNeedle = $this->identities->text($neighborhoodValue);
                $neighborhood = collect($cityData['neighborhoods'] ?? [])->first(
                    fn (string $candidate): bool => $this->identities->text($candidate) === $neighborhoodNeedle
                );
                if (! $neighborhood) {
                    throw ValidationException::withMessages(['neighborhood' => ['Unknown neighborhood for this city.']]);
                }
            }
        }

        return [$city, $neighborhood];
    }

    private function like(string $value): string
    {
        return '%'.addcslashes(trim($value), '\\%_').'%';
    }

    private function sortForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortForHash($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->sortForHash($item), $value);
    }
}
