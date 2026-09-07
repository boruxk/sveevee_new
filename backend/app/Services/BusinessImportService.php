<?php

namespace App\Services;

use App\Exceptions\BusinessImportException;
use App\Models\BusinessImportPage;
use App\Models\Page;
use App\Models\User;
use App\Rules\CleanContent;
use App\Support\CatalogTopics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessImportService
{
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
    ];

    private const ADDRESS_FIELDS = ['street', 'number', 'city', 'neighborhood'];

    private const SOCIAL_FIELDS = ['facebook', 'instagram', 'tiktok', 'telegram', 'x'];

    public function __construct(
        private readonly AiWorkPageService $pages,
        private readonly PageIdentityService $identities,
    ) {}

    public function search(array $input): array
    {
        $filters = Validator::make($input, [
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
        $prepared = [
            ...$input,
            'type' => Page::TYPE_BUSINESS,
            'name' => trim((string) ($input['name'] ?? '')),
            'contact_email' => $input['contact_email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'address' => is_array($input['address'] ?? null) ? $input['address'] : [],
        ];

        $data = Validator::make($prepared, [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS])],
            'name' => ['nullable', 'string', 'max:255', new CleanContent],
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

        if (! filled($data['name'] ?? null)
            && ! filled($data['phone'] ?? null)
            && ! filled($data['contact_email'] ?? null)) {
            throw ValidationException::withMessages([
                'identity' => ['Provide at least one of name, phone, or contact_email.'],
            ]);
        }

        return $this->identities->exactMatches($data, $excludePageId, allowSingleContactSignal: true)->all();
    }

    public function upsert(string $clientId, array $input): array
    {
        $this->assertKnownFields($input);
        $id = Validator::make(['id' => $input['id'] ?? null], [
            'id' => ['nullable', 'integer', 'min:1'],
        ])->validate()['id'] ?? null;

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
            $page = $this->pages->create($worker, $data, allowSingleContactDuplicate: true);
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

        unset($input['id'], $input['type']);
        if (array_intersect(array_keys($input), array_diff(self::TOP_LEVEL_FIELDS, ['id', 'type'])) === []) {
            throw ValidationException::withMessages(['business' => ['Provide at least one field to update.']]);
        }

        $merged = $this->mergeWithExisting($page, $input);
        $data = $this->pages->validate($merged);

        $page = DB::transaction(function () use ($clientId, $page, $data): Page {
            $page = $this->pages->update($page, $data, allowSingleContactDuplicate: true);
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
