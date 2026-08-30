<?php

namespace App\Services;

use App\Exceptions\ExactPageDuplicateException;
use App\Models\Page;
use App\Models\User;
use App\Rules\CleanContent;
use App\Support\CatalogTopics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiWorkPageService
{
    public const DEFAULT_OPENING_HOURS = [
        ['weekday' => 'sunday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
        ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'tuesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'wednesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'thursday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'friday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '13:00'],
        ['weekday' => 'saturday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
    ];

    public const PALETTE_KEYS = [
        'amber-dawn',
        'olive-mist',
        'sea-glass',
        'berry-ink',
        'midnight-copper',
        'sunset-cream',
        'forest-linen',
        'sky-sand',
        'plum-sand',
        'charcoal-rose',
        'electric-orchid',
        'cobalt-pop',
        'coral-punch',
        'turquoise-market',
        'golden-hour',
        'deep-teal',
        'violet-night',
        'ruby-noir',
        'mango-leaf',
        'blueberry-cream',
        'lavender-lime',
        'peach-fuchsia',
        'neon-garden',
        'indigo-sun',
        'aqua-coral',
        'rose-gold',
        'black-lime',
        'navy-rose',
        'papaya-sky',
        'mint-plum',
        'orange-violet',
        'crimson-steel',
        'sunlit-grape',
        'green-citrus',
        'denim-peach',
        'raspberry-mint',
        'ocean-night',
        'espresso-rose',
        'saffron-sea',
        'pink-azure',
    ];

    public function __construct(private readonly PageIdentityService $identities) {}

    public function automaticPalette(array $input, mixed $requested = null): string
    {
        $requested = trim((string) $requested);
        if (in_array($requested, self::PALETTE_KEYS, true)) {
            return $requested;
        }

        $address = is_array($input['address'] ?? null) ? $input['address'] : [];
        $seed = $this->identities->text(implode('|', [
            $input['type'] ?? '',
            $input['name'] ?? '',
            $input['category_key'] ?? $input['category'] ?? '',
            $address['city'] ?? $input['city'] ?? '',
            $address['neighborhood'] ?? $input['neighborhood'] ?? '',
        ]));
        $index = hexdec(substr(hash('sha256', $seed), 0, 8)) % count(self::PALETTE_KEYS);

        return self::PALETTE_KEYS[$index];
    }

    public function validate(array $input): array
    {
        $type = trim((string) ($input['type'] ?? ''));
        $scope = $type === Page::TYPE_COMMUNITY
            ? CatalogTopics::SCOPE_COMMUNITY_PAGES
            : CatalogTopics::SCOPE_BUSINESS_PAGES;
        $address = is_array($input['address'] ?? null) ? $input['address'] : [];
        $cities = $this->cities();
        $city = $this->canonicalValue($address['city'] ?? null, $cities);
        $neighborhoods = $city ? $this->neighborhoods($city) : [];
        $neighborhood = filled($address['neighborhood'] ?? null)
            ? $this->canonicalValue($address['neighborhood'], $neighborhoods)
            : null;
        $website = $this->normalizedUrl($input['website'] ?? null);
        $category = $this->categoryKey($input['category_key'] ?? $input['category'] ?? null, $scope);

        $prepared = [
            ...$input,
            'type' => $type,
            'name' => trim((string) ($input['name'] ?? '')),
            'public_description' => $this->nullableString($input['public_description'] ?? null),
            'contact_email' => $this->nullableString($input['contact_email'] ?? null),
            'phone' => $this->nullableString($input['phone'] ?? null),
            'whatsapp' => $this->nullableString($input['whatsapp'] ?? null),
            'website' => $website,
            'category_key' => $category ?? trim((string) ($input['category_key'] ?? $input['category'] ?? '')),
            'palette_key' => $this->nullableString($input['palette_key'] ?? null) ?? 'amber-dawn',
            'address' => [
                'street' => $this->nullableString($address['street'] ?? null),
                'number' => $this->nullableString($address['number'] ?? null),
                'city' => $city ?? trim((string) ($address['city'] ?? '')),
                'neighborhood' => $neighborhood ?? $this->nullableString($address['neighborhood'] ?? null),
            ],
            'socials' => is_array($input['socials'] ?? null) ? $input['socials'] : [],
            'opening_hours' => is_array($input['opening_hours'] ?? null) ? $input['opening_hours'] : [],
        ];

        return Validator::make($prepared, [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])],
            'name' => ['required', 'string', 'max:255', new CleanContent],
            'public_description' => ['nullable', 'string', 'max:3000', new CleanContent],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope($scope))],
            'palette_key' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.number' => ['nullable', 'string', 'max:40'],
            'address.city' => ['required', 'string', 'max:120', Rule::in($cities)],
            'address.neighborhood' => ['nullable', 'string', 'max:120', Rule::in($neighborhoods)],
            'socials' => ['nullable', 'array'],
            'socials.facebook' => ['nullable', 'string', 'max:2048'],
            'socials.instagram' => ['nullable', 'string', 'max:2048'],
            'socials.tiktok' => ['nullable', 'string', 'max:2048'],
            'socials.telegram' => ['nullable', 'string', 'max:2048'],
            'opening_hours' => ['nullable', 'array'],
            'opening_hours.*.weekday' => ['required', Rule::in(array_column(self::DEFAULT_OPENING_HOURS, 'weekday'))],
            'opening_hours.*.is_open' => ['required', 'boolean'],
            'opening_hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'opening_hours.*.closes_at' => ['nullable', 'date_format:H:i'],
        ])->validate();
    }

    public function create(User $worker, array $data): Page
    {
        $identity = $this->identities->fromInput($data);

        return Cache::lock('ai-page-identity:'.$identity['identity_hash'], 10)->block(5, function () use ($worker, $data): Page {
            $matches = $this->identities->exactMatches($data);
            if ($matches->isNotEmpty()) {
                throw new ExactPageDuplicateException($matches->all());
            }

            return DB::transaction(function () use ($worker, $data): Page {
                $page = new Page;
                $page->user_id = $worker->id;
                $page->created_by_user_id = $worker->id;
                $page->type = $data['type'];
                $page->is_unclaimed = true;
                $this->fill($page, $data);
                $page->save();

                return $page;
            });
        });
    }

    public function update(Page $page, array $data): Page
    {
        $identity = $this->identities->fromInput($data);

        return Cache::lock('ai-page-identity:'.$identity['identity_hash'], 10)->block(5, function () use ($page, $data): Page {
            $matches = $this->identities->exactMatches($data, $page->id);
            if ($matches->isNotEmpty()) {
                throw new ExactPageDuplicateException($matches->all());
            }

            $page->type = $data['type'];
            $this->fill($page, $data);
            $page->save();

            return $page;
        });
    }

    public function summary(Page $page): array
    {
        $setup = is_array($page->setup) ? $page->setup : [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return [
            'id' => $page->id,
            'name' => $page->name,
            'type' => $page->type,
            'category_key' => $page->category_key,
            'palette_key' => $page->palette_key,
            'address_details' => [
                'city' => $address['city'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? null,
            ],
            'public_path' => $page->public_path,
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    public function editableData(Page $page): array
    {
        $setup = is_array($page->setup) ? $page->setup : [];
        $contact = is_array($setup['contact'] ?? null) ? $setup['contact'] : [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];
        $socials = is_array($setup['socials'] ?? null) ? $setup['socials'] : [];

        return [
            'type' => $page->type,
            'name' => $page->name,
            'public_description' => $page->public_description,
            'contact_email' => $page->contact_email ?? ($contact['email'] ?? null),
            'phone' => $page->phone ?? ($contact['tel'] ?? null),
            'whatsapp' => $contact['whatsapp'] ?? null,
            'website' => $setup['website'] ?? null,
            'category_key' => $page->category_key,
            'palette_key' => $page->palette_key,
            'address' => [
                'street' => $address['street'] ?? null,
                'number' => $address['number'] ?? null,
                'city' => $address['city'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? null,
            ],
            'socials' => $socials,
            'opening_hours' => is_array($setup['opening_hours'] ?? null) ? $setup['opening_hours'] : [],
        ];
    }

    public function bulkEditableData(Page $page): array
    {
        $data = $this->editableData($page);
        unset($data['palette_key']);

        return [
            'id' => $page->id,
            ...$data,
        ];
    }

    public function validateBulkEditFilters(array $input): array
    {
        $cities = $this->cities();
        $city = filled($input['city'] ?? null)
            ? $this->canonicalValue($input['city'], $cities)
            : null;
        $neighborhoods = $city ? $this->neighborhoods($city) : [];
        $neighborhood = filled($input['neighborhood'] ?? null)
            ? $this->canonicalValue($input['neighborhood'], $neighborhoods)
            : null;
        $categoryKeys = array_values(array_unique([
            ...CatalogTopics::keysForScope(CatalogTopics::SCOPE_BUSINESS_PAGES),
            ...CatalogTopics::keysForScope(CatalogTopics::SCOPE_COMMUNITY_PAGES),
        ]));
        $category = trim((string) ($input['category_key'] ?? ''));

        return Validator::make([
            'city' => $city ?? trim((string) ($input['city'] ?? '')),
            'neighborhood' => $neighborhood ?? trim((string) ($input['neighborhood'] ?? '')),
            'category_key' => $category,
            'id_from' => filled($input['id_from'] ?? null) ? $input['id_from'] : null,
            'id_to' => filled($input['id_to'] ?? null) ? $input['id_to'] : null,
        ], [
            'city' => ['nullable', 'required_with:neighborhood', Rule::in($cities)],
            'neighborhood' => ['nullable', Rule::in($neighborhoods)],
            'category_key' => ['nullable', Rule::in($categoryKeys)],
            'id_from' => ['nullable', 'integer', 'min:1'],
            'id_to' => ['nullable', 'integer', 'min:1', 'gte:id_from'],
        ])->validate();
    }

    public function defaultPreferences(): array
    {
        return [
            'type' => Page::TYPE_BUSINESS,
            'city' => '',
            'neighborhood' => '',
            'category_key' => '',
            'palette_key' => 'amber-dawn',
        ];
    }

    public function validatePreferences(array $input): array
    {
        $defaults = [...$this->defaultPreferences(), ...$input];
        $type = trim((string) $defaults['type']);
        $scope = $type === Page::TYPE_COMMUNITY
            ? CatalogTopics::SCOPE_COMMUNITY_PAGES
            : CatalogTopics::SCOPE_BUSINESS_PAGES;
        $cities = $this->cities();
        $city = filled($defaults['city']) ? $this->canonicalValue($defaults['city'], $cities) : '';
        $neighborhoods = $city ? $this->neighborhoods($city) : [];
        $neighborhood = filled($defaults['neighborhood'])
            ? $this->canonicalValue($defaults['neighborhood'], $neighborhoods)
            : '';
        $category = filled($defaults['category_key'])
            ? $this->categoryKey($defaults['category_key'], $scope)
            : '';

        return Validator::make([
            'type' => $type,
            'city' => $city ?? trim((string) $defaults['city']),
            'neighborhood' => $neighborhood ?? trim((string) $defaults['neighborhood']),
            'category_key' => $category ?? trim((string) $defaults['category_key']),
            'palette_key' => trim((string) $defaults['palette_key']),
        ], [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])],
            'city' => ['nullable', Rule::in($cities)],
            'neighborhood' => ['nullable', Rule::in($neighborhoods)],
            'category_key' => ['nullable', Rule::in(CatalogTopics::keysForScope($scope))],
            'palette_key' => ['required', 'string', 'max:50'],
        ])->validate();
    }

    private function fill(Page $page, array $data): void
    {
        $address = $data['address'];
        $contact = [
            'tel' => $this->nullableString($data['phone'] ?? null),
            'email' => $this->nullableString($data['contact_email'] ?? null),
            'whatsapp' => $this->nullableString($data['whatsapp'] ?? null),
        ];

        $page->fill([
            'name' => $data['name'],
            'public_description' => $this->nullableString($data['public_description'] ?? null),
            'contact_email' => $contact['email'],
            'phone' => $contact['tel'],
            'address' => collect([$address['street'], $address['number'], $address['neighborhood'], $address['city']])->filter()->implode(', '),
            'category_key' => $data['category_key'],
            'palette_key' => $data['palette_key'] ?? 'amber-dawn',
            'setup' => [
                'website' => $data['website'] ?? null,
                'contact' => $contact,
                'address' => $address,
                'socials' => [
                    'facebook' => $this->nullableString($data['socials']['facebook'] ?? null),
                    'instagram' => $this->nullableString($data['socials']['instagram'] ?? null),
                    'tiktok' => $this->nullableString($data['socials']['tiktok'] ?? null),
                    'telegram' => $this->nullableString($data['socials']['telegram'] ?? null),
                ],
                'opening_hours' => $this->openingHours($data['opening_hours'] ?? []),
                'features' => ['store' => false, 'services' => false, 'events' => false, 'price_list' => false],
            ],
            'logo_path' => null,
            'logo_original_name' => null,
            'banner_path' => null,
            'banner_original_name' => null,
        ]);
    }

    private function openingHours(array $openingHours): array
    {
        $items = collect($openingHours)->filter(fn ($item) => is_array($item) && filled($item['weekday'] ?? null))->keyBy('weekday');

        return collect(self::DEFAULT_OPENING_HOURS)->map(function (array $default) use ($items): array {
            $item = $items->get($default['weekday'], []);
            $isOpen = (bool) ($item['is_open'] ?? $default['is_open']);

            return [
                'weekday' => $default['weekday'],
                'is_open' => $isOpen,
                'opens_at' => $isOpen ? ($item['opens_at'] ?? $default['opens_at']) : null,
                'closes_at' => $isOpen ? ($item['closes_at'] ?? $default['closes_at']) : null,
            ];
        })->values()->all();
    }

    private function categoryKey(mixed $value, string $scope): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if ($canonical = CatalogTopics::canonicalKeyForScope($value, $scope)) {
            return $canonical;
        }

        $needle = $this->identities->text($value);
        foreach (CatalogTopics::keysForScope($scope) as $key) {
            $topic = CatalogTopics::findByKey($key);
            $candidates = [$key, $topic['slug'] ?? null, ...($topic['aliases'] ?? []), ...array_values($topic['labels'] ?? [])];
            if (collect($candidates)->filter()->contains(fn ($candidate) => $this->identities->text($candidate) === $needle)) {
                return $key;
            }
        }

        return null;
    }

    private function cities(): array
    {
        return collect(config('locations.cities', []))->pluck('name')->filter()->values()->all();
    }

    private function neighborhoods(string $city): array
    {
        return collect(config('locations.cities', []))->firstWhere('name', $city)['neighborhoods'] ?? [];
    }

    private function canonicalValue(mixed $value, array $options): ?string
    {
        $needle = $this->identities->text($value);
        if ($needle === '') {
            return null;
        }

        return collect($options)->first(fn ($option) => $this->identities->text($option) === $needle);
    }

    private function normalizedUrl(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : (preg_match('~^https?://~i', $value) ? $value : 'https://'.$value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
