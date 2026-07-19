<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageController extends Controller
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

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function mine(Request $request, string $type)
    {
        $this->validateType($type);

        $page = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->with(['user.profile', 'ads.user.profile', 'ads.page'])
            ->first();

        return ApiResponseService::success($page ? $this->payloads->page($page, withAds: true) : null);
    }

    public function upsert(Request $request, string $type)
    {
        $this->validateType($type);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'public_description' => ['nullable', 'string', 'max:3000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'palette_key' => ['nullable', 'string', 'max:50'],
            'setup' => ['nullable'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'banner' => ['nullable', 'image', 'max:6144'],
        ]);

        $setup = $this->normalizedSetup($request->input('setup'));
        $contact = $setup['contact'] ?? [];
        $addressDetails = $setup['address'] ?? [];

        $page = Page::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'type' => $type,
        ]);

        $page->fill([
            'name' => $data['name'],
            'public_description' => $data['public_description'] ?? null,
            'contact_email' => $data['contact_email'] ?? $contact['email'] ?? $request->user()->email,
            'phone' => $data['phone'] ?? $contact['tel'] ?? null,
            'address' => $data['address'] ?? $this->addressLine($addressDetails),
            'palette_key' => $data['palette_key'] ?? 'amber-dawn',
            'setup' => $setup,
        ]);

        if ($request->hasFile('logo')) {
            $page->logo_path = $request->file('logo')->store('pages/logos', 'public');
        }

        if ($request->hasFile('banner')) {
            $page->banner_path = $request->file('banner')->store('pages/banners', 'public');
        }

        $page->save();

        return ApiResponseService::success($this->payloads->page($page->fresh(['user.profile', 'ads.user.profile', 'ads.page']), withAds: true), 'Page saved.');
    }

    public function show(Page $page)
    {
        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $page->load(['user.profile', 'ads.user.profile', 'ads.page']);

        return ApiResponseService::success($this->payloads->page($page, withAds: true));
    }

    private function validateType(string $type): void
    {
        validator(['type' => $type], [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])],
        ])->validate();
    }

    private function normalizedSetup(mixed $setup): array
    {
        if (is_array($setup)) {
            $decoded = $setup;
        } elseif (is_string($setup) && trim($setup) !== '') {
            $decoded = json_decode($setup, true);

            $decoded = is_array($decoded) ? $decoded : [];
        } else {
            $decoded = [];
        }

        $contact = is_array($decoded['contact'] ?? null) ? $decoded['contact'] : [];
        $address = is_array($decoded['address'] ?? null) ? $decoded['address'] : [];

        return array_merge($decoded, [
            'contact' => [
                'tel' => $this->nullableString($contact['tel'] ?? null),
                'email' => $this->nullableString($contact['email'] ?? null),
                'whatsapp' => $this->nullableString($contact['whatsapp'] ?? null),
            ],
            'address' => [
                'street' => $this->nullableString($address['street'] ?? null),
                'number' => $this->nullableString($address['number'] ?? null),
                'city' => $this->nullableString($address['city'] ?? null),
            ],
            'opening_hours' => $this->normalizedOpeningHours($decoded['opening_hours'] ?? []),
        ]);
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
            $address['city'] ?? null,
        ])->filter(fn ($value) => filled($value))->implode(', ');

        return $line === '' ? null : $line;
    }
}
