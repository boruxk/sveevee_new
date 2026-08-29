<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Support\CatalogTopics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiWorkPageController extends Controller
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

    public function __construct(private readonly PayloadService $payloads) {}

    public function index(Request $request)
    {
        $pages = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('is_unclaimed', true)
            ->with(['user.profile', 'prices', 'products', 'services', 'events'])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->latest('updated_at')
            ->get()
            ->map(fn (Page $page): array => $this->payloads->page($page));

        return ApiResponseService::success(['pages' => $pages]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $page = new Page;
        $page->user_id = $request->user()->id;
        $page->created_by_user_id = $request->user()->id;
        $page->type = $data['type'];
        $page->is_unclaimed = true;
        $this->fillPage($page, $data);
        $page->save();

        return ApiResponseService::success($this->pagePayload($page), 'Page created.', 201);
    }

    public function update(Request $request, Page $page)
    {
        $this->ensureEditable($request, $page);
        $data = $this->validated($request);
        $page->type = $data['type'];
        $this->fillPage($page, $data);
        $page->save();

        return ApiResponseService::success($this->pagePayload($page), 'Page updated.');
    }

    public function destroy(Request $request, Page $page)
    {
        $this->ensureEditable($request, $page);
        $page->delete();

        return ApiResponseService::success(null, 'Page deleted.');
    }

    private function validated(Request $request): array
    {
        $pageType = trim((string) $request->input('type'));
        $catalogScope = $pageType === Page::TYPE_COMMUNITY
            ? CatalogTopics::SCOPE_COMMUNITY_PAGES
            : CatalogTopics::SCOPE_BUSINESS_PAGES;
        $categoryKey = trim((string) $request->input('category_key'));
        $website = $this->normalizedUrl($request->input('website'));
        $sourceUrl = $this->normalizedUrl($request->input('source_url'));
        $request->merge([
            'type' => $pageType,
            'name' => trim((string) $request->input('name')),
            'public_description' => trim((string) $request->input('public_description')),
            'category_key' => CatalogTopics::canonicalKeyForScope(
                $categoryKey,
                $catalogScope
            ) ?? $categoryKey,
            'website' => $website,
            'source_url' => $sourceUrl,
        ]);

        return $request->validate([
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])],
            'name' => ['required', 'string', 'max:255', new CleanContent],
            'public_description' => ['nullable', 'string', 'max:3000', new CleanContent],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope($catalogScope))],
            'palette_key' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.number' => ['nullable', 'string', 'max:40'],
            'address.city' => ['required', 'string', 'max:120'],
            'address.neighborhood' => ['nullable', 'string', 'max:120'],
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
            'source_url' => ['required', 'url:http,https', 'max:2048'],
            'source_checked_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
    }

    private function fillPage(Page $page, array $data): void
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
            'address' => collect([
                $address['street'] ?? null,
                $address['number'] ?? null,
                $address['neighborhood'] ?? null,
                $address['city'] ?? null,
            ])->filter(fn ($value) => filled($value))->implode(', '),
            'category_key' => $data['category_key'],
            'palette_key' => $data['palette_key'] ?? 'amber-dawn',
            'source_url' => $data['source_url'],
            'source_checked_at' => $data['source_checked_at'],
            'setup' => [
                'website' => $data['website'] ?? null,
                'contact' => $contact,
                'address' => [
                    'street' => $this->nullableString($address['street'] ?? null),
                    'number' => $this->nullableString($address['number'] ?? null),
                    'city' => $this->nullableString($address['city'] ?? null),
                    'neighborhood' => $this->nullableString($address['neighborhood'] ?? null),
                ],
                'socials' => [
                    'facebook' => $this->nullableString($data['socials']['facebook'] ?? null),
                    'instagram' => $this->nullableString($data['socials']['instagram'] ?? null),
                    'tiktok' => $this->nullableString($data['socials']['tiktok'] ?? null),
                    'telegram' => $this->nullableString($data['socials']['telegram'] ?? null),
                ],
                'opening_hours' => $this->normalizedOpeningHours($data['opening_hours'] ?? []),
                'features' => [
                    'store' => false,
                    'services' => false,
                    'events' => false,
                    'price_list' => false,
                ],
            ],
            'logo_path' => null,
            'logo_original_name' => null,
            'banner_path' => null,
            'banner_original_name' => null,
        ]);
    }

    private function pagePayload(Page $page): array
    {
        return $this->payloads->page($page->fresh([
            'user.profile',
            'prices',
            'products',
            'services',
            'events',
        ])->loadCount('ratings')->loadAvg('ratings', 'rating'));
    }

    private function ensureEditable(Request $request, Page $page): void
    {
        abort_unless(
            $page->is_unclaimed
                && $page->user_id === $request->user()->id,
            404
        );
    }

    private function normalizedOpeningHours(array $openingHours): array
    {
        $items = collect($openingHours)
            ->filter(fn ($item) => is_array($item) && filled($item['weekday'] ?? null))
            ->keyBy('weekday');

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

    private function normalizedUrl(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return preg_match('~^https?://~i', $value) ? $value : 'https://'.$value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
