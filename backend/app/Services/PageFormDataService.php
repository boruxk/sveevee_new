<?php

namespace App\Services;

use App\Models\Page;
use App\Rules\CleanContent;
use App\Support\CatalogTopics;
use App\Support\PublicImageVariants;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Apply a validated page form without replacing ownership or import provenance. */
class PageFormDataService
{
    private const DEFAULT_OPENING_HOURS = [
        ['weekday' => 'sunday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
        ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'tuesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'wednesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'thursday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'friday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '13:00'],
        ['weekday' => 'saturday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
    ];

    public const FIELDS = [
        'name', 'public_description', 'contact_email', 'phone', 'address',
        'category_key', 'palette_key', 'setup',
        'logo_path', 'logo_original_name', 'banner_path', 'banner_original_name',
    ];

    /** Validate and normalize the same multipart form for user and admin entry points. */
    public function validate(Request $request, string $type, ?string $fallbackEmail = null, bool $adminCreation = false): array
    {
        if ($adminCreation) {
            $this->validateAdminSetup($request);
        }
        $catalogScope = $this->catalogScopeForType($type);
        $this->normalizeCategoryKey($request, $catalogScope);
        $this->normalizeWebsite($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'public_description' => ['nullable', 'string', 'max:3000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope($catalogScope))],
            'palette_key' => ['nullable', 'string', 'max:50'],
            'setup' => ['nullable'],
            'logo' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'logo_remove' => ['nullable', 'boolean'],
            'banner' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'banner_remove' => ['nullable', 'boolean'],
        ]);

        $decodedSetup = $this->decodedSetup($request->input('setup'));
        $this->validateBusinessDetails($decodedSetup, $type);
        if ($adminCreation && ! empty($decodedSetup['opening_hours'])) {
            $hoursByDay = array_column($decodedSetup['opening_hours'], null, 'weekday');
            $decodedSetup['opening_hours'] = array_map(
                fn (array $day): array => $hoursByDay[$day['weekday']] ?? [
                    'weekday' => $day['weekday'], 'is_open' => false, 'opens_at' => null, 'closes_at' => null,
                ],
                self::DEFAULT_OPENING_HOURS,
            );
        }
        $setup = $this->normalizedSetup($decodedSetup);
        if ($adminCreation && empty($decodedSetup['opening_hours'])) {
            $setup['opening_hours'] = [];
        }
        $setup['website'] = $data['website'] ?? null;
        $setup['service_areas'] = $type === Page::TYPE_BUSINESS ? $setup['service_areas'] : [];
        $setup['specialties'] = $type === Page::TYPE_BUSINESS ? $setup['specialties'] : [];
        $setup['features'] = [
            'store' => $type === Page::TYPE_BUSINESS ? $setup['features']['store'] : false,
            'services' => $type === Page::TYPE_BUSINESS ? $setup['features']['services'] : false,
            'events' => $type === Page::TYPE_COMMUNITY ? $setup['features']['events'] : false,
            'price_list' => $type === Page::TYPE_BUSINESS ? $setup['features']['price_list'] : false,
        ];
        $contact = $setup['contact'] ?? [];
        $addressDetails = $setup['address'] ?? [];

        $proposed = [
            'name' => $data['name'],
            'public_description' => $data['public_description'] ?? null,
            'contact_email' => $data['contact_email'] ?? $contact['email'] ?? $fallbackEmail,
            'phone' => $data['phone'] ?? $contact['tel'] ?? null,
            'address' => $data['address'] ?? $this->addressLine($addressDetails),
            'category_key' => $data['category_key'],
            'palette_key' => $data['palette_key'] ?? 'amber-dawn',
            'setup' => $setup,
        ];
        // An account-email fallback is not a business detail supplied for matching.
        $matchingData = [...$proposed, 'contact_email' => $data['contact_email'] ?? $contact['email'] ?? null];

        return ['proposed' => $proposed, 'matching' => $matchingData, 'setup' => $setup, 'address' => $addressDetails];
    }

    /** Fill only; caller saves in its transaction and deletes returned files after commit. */
    public function fill(Page $page, array $data): array
    {
        $data = Arr::only($data, self::FIELDS);
        if (array_key_exists('setup', $data)) {
            $setup = is_array($data['setup']) ? $data['setup'] : [];
            foreach (array_keys($setup) as $key) {
                if (str_starts_with($key, 'imported_')) {
                    unset($setup[$key]);
                }
            }
            // These belong to the imported source, not to the editable page form.
            foreach (['imported_attributions', 'imported_categories'] as $key) {
                if (array_key_exists($key, $page->setup ?? [])) {
                    $setup[$key] = $page->setup[$key];
                }
            }
            $data['setup'] = $setup;
        }

        $obsolete = [];
        foreach (['logo_path', 'banner_path'] as $field) {
            if (array_key_exists($field, $data) && $page->{$field} && $page->{$field} !== $data[$field]) {
                $obsolete[] = $page->{$field};
                array_push($obsolete, ...PublicImageVariants::variantPaths($page->{$field}));
            }
        }
        $page->fill($data);

        return array_values(array_unique($obsolete));
    }

    private function catalogScopeForType(string $type): string
    {
        return $type === Page::TYPE_COMMUNITY
            ? CatalogTopics::SCOPE_COMMUNITY_PAGES
            : CatalogTopics::SCOPE_BUSINESS_PAGES;
    }

    private function validateAdminSetup(Request $request): void
    {
        // Reject malformed structures before string/array normalization. The
        // existing owner form keeps its established normalization behavior.
        $request->validate(['category_key' => ['required', 'string'], 'website' => ['nullable', 'string']]);
        $setup = $request->input('setup');
        if ($setup === null || $setup === '') {
            $setup = [];
        } elseif (is_string($setup)) {
            try {
                $setup = json_decode($setup, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['setup' => ['The setup must be a valid JSON object.']]);
            }
        }
        if (! is_array($setup) || ($setup !== [] && array_is_list($setup))) {
            throw ValidationException::withMessages(['setup' => ['The setup must be an object.']]);
        }
        validator($setup, [
            'website' => ['nullable', 'string', 'max:2048'],
            'contact' => ['nullable', 'array'], 'contact.tel' => ['nullable', 'string', 'max:40'],
            'contact.email' => ['nullable', 'email', 'max:255'], 'contact.whatsapp' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'array'], 'address.street' => ['nullable', 'string', 'max:300'],
            'address.number' => ['nullable', 'string', 'max:40'], 'address.city' => ['nullable', 'string', 'max:120'],
            'address.neighborhood' => ['nullable', 'string', 'max:120'],
            'socials' => ['nullable', 'array'], 'socials.*' => ['nullable', 'string', 'max:2048'],
            'features' => ['nullable', 'array'], 'features.*' => ['nullable', 'boolean'],
            'services' => ['nullable', 'array'], 'services.title' => ['nullable', 'string', 'max:255'],
            'services.description' => ['nullable', 'string', 'max:3000'],
            'opening_hours' => ['nullable', 'array', 'max:7'], 'opening_hours.*' => ['required', 'array'],
            'opening_hours.*.weekday' => ['required', 'string', 'distinct', Rule::in(array_column(self::DEFAULT_OPENING_HOURS, 'weekday'))],
            'opening_hours.*.is_open' => ['required', 'boolean'],
            'opening_hours.*.opens_at' => ['nullable', 'required_if:opening_hours.*.is_open,true', 'date_format:H:i'],
            'opening_hours.*.closes_at' => ['nullable', 'required_if:opening_hours.*.is_open,true', 'date_format:H:i'],
        ])->validate();
    }

    private function normalizeCategoryKey(Request $request, string $scope): void
    {
        $key = trim((string) $request->input('category_key', ''));

        if ($key === '') {
            return;
        }

        $request->merge([
            'category_key' => CatalogTopics::canonicalKeyForScope($key, $scope) ?? $key,
        ]);
    }

    public function normalizedSetup(mixed $setup): array
    {
        $decoded = $this->decodedSetup($setup);

        $contact = is_array($decoded['contact'] ?? null) ? $decoded['contact'] : [];
        $address = is_array($decoded['address'] ?? null) ? $decoded['address'] : [];
        $socials = is_array($decoded['socials'] ?? null) ? $decoded['socials'] : [];
        $features = is_array($decoded['features'] ?? null) ? $decoded['features'] : [];
        $services = is_array($decoded['services'] ?? null) ? $decoded['services'] : [];

        return array_merge($decoded, [
            'website' => $this->nullableString($decoded['website'] ?? null),
            'contact' => [
                'tel' => $this->nullableString($contact['tel'] ?? null),
                'email' => $this->nullableString($contact['email'] ?? null),
                'whatsapp' => $this->nullableString($contact['whatsapp'] ?? null),
            ],
            'address' => [
                'street' => $this->nullableString($address['street'] ?? null),
                'number' => $this->nullableString($address['number'] ?? null),
                'city' => $this->nullableString($address['city'] ?? null),
                'neighborhood' => $this->nullableString($address['neighborhood'] ?? null),
            ],
            'socials' => [
                'facebook' => $this->nullableString($socials['facebook'] ?? null),
                'instagram' => $this->nullableString($socials['instagram'] ?? null),
                'tiktok' => $this->nullableString($socials['tiktok'] ?? null),
                'x' => $this->nullableString($socials['x'] ?? null),
                'telegram' => $this->nullableString($socials['telegram'] ?? null),
            ],
            'opening_hours' => $this->normalizedOpeningHours($decoded['opening_hours'] ?? []),
            'service_areas' => $this->normalizedStringList($decoded['service_areas'] ?? [], 10),
            'specialties' => $this->normalizedStringList($decoded['specialties'] ?? [], 50),
            'features' => [
                'store' => $this->booleanValue($features['store'] ?? null, false),
                'services' => $this->booleanValue($features['services'] ?? null, false),
                'events' => $this->booleanValue($features['events'] ?? null, false),
                'price_list' => $this->booleanValue($features['price_list'] ?? null, false),
            ],
            'services' => [
                'title' => $this->nullableString($services['title'] ?? null),
                'description' => $this->nullableString($services['description'] ?? null),
            ],
        ]);
    }

    private function decodedSetup(mixed $setup): array
    {
        if (is_array($setup)) {
            return $setup;
        }

        if (! is_string($setup) || trim($setup) === '') {
            return [];
        }

        $decoded = json_decode($setup, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function validateBusinessDetails(array $setup, string $type): void
    {
        if ($type !== Page::TYPE_BUSINESS) {
            return;
        }

        validator($setup, [
            'service_areas' => ['nullable', 'array', 'max:10'],
            'service_areas.*' => ['required', 'string', 'max:255', 'distinct'],
            'specialties' => ['nullable', 'array', 'max:50'],
            'specialties.*' => ['required', 'string', 'max:120', 'distinct', new CleanContent],
        ])->validate();
    }

    private function normalizedStringList(mixed $values, int $limit): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($value) => is_string($value) || is_numeric($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn (string $value) => mb_strtolower($value, 'UTF-8'))
            ->take($limit)
            ->values()
            ->all();
    }

    public function booleanValue(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeWebsite(Request $request): void
    {
        $website = $request->input('website');

        if ($website === null) {
            $setup = $request->input('setup');
            if (is_string($setup) && trim($setup) !== '') {
                $setup = json_decode($setup, true);
            }

            $website = is_array($setup) ? ($setup['website'] ?? null) : null;
        }

        $website = trim((string) $website);
        if ($website !== '' && ! preg_match('~^https?://~i', $website)) {
            $website = 'https://'.$website;
        }

        $request->merge(['website' => $website === '' ? null : $website]);
    }

    private function normalizedOpeningHours(mixed $openingHours): array
    {
        $items = collect(is_array($openingHours) ? $openingHours : [])
            ->filter(fn ($item) => is_array($item) && filled($item['weekday'] ?? null))
            ->keyBy('weekday');

        return collect(self::DEFAULT_OPENING_HOURS)
            ->map(function (array $default) use ($items): array {
                $item = $items->get($default['weekday'], []);
                $isOpen = (bool) ($item['is_open'] ?? $default['is_open']);

                return [
                    'weekday' => $default['weekday'],
                    'is_open' => $isOpen,
                    'opens_at' => $isOpen ? ($item['opens_at'] ?? $default['opens_at']) : null,
                    'closes_at' => $isOpen ? ($item['closes_at'] ?? $default['closes_at']) : null,
                ];
            })
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function addressLine(array $address): ?string
    {
        $line = collect([
            $address['street'] ?? null,
            $address['number'] ?? null,
            $address['neighborhood'] ?? null,
            $address['city'] ?? null,
        ])->filter(fn ($value) => filled($value))->implode(', ');

        return $line === '' ? null : $line;
    }
}
