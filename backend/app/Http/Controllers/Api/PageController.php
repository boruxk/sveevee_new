<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\BusinessPageClaimConflictService;
use App\Services\BusinessPageMatchService;
use App\Services\PageClaimService;
use App\Services\PageDeletionService;
use App\Services\PageFormDataService;
use App\Services\PageIdentityService;
use App\Services\PayloadService;
use App\Support\CatalogTopics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    use HandlesUploadedImages;

    private const DEFAULT_OPENING_HOURS = [
        ['weekday' => 'sunday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
        ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'tuesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'wednesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'thursday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'friday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '13:00'],
        ['weekday' => 'saturday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
    ];

    public function __construct(
        private readonly PayloadService $payloads,
        private readonly PageDeletionService $deletions,
        private readonly BusinessPageMatchService $businessMatches,
        private readonly BusinessPageClaimConflictService $conflicts,
        private readonly PageClaimService $claims,
        private readonly PageFormDataService $forms,
        private readonly PageIdentityService $identities,
    ) {}

    public function mine(Request $request, string $type)
    {
        if ($request->user()->hasRole('ai_worker')) {
            return ApiResponseService::error('Use the AI works area to manage generated pages.', status: 403);
        }

        $this->validateType($type);

        $page = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->with(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->first();

        return ApiResponseService::success($page ? $this->payloads->page($page, withAds: true) : null);
    }

    public function upsert(Request $request, string $type)
    {
        if ($request->user()->hasRole('ai_worker')) {
            return ApiResponseService::error('Use the AI works area to manage generated pages.', status: 403);
        }

        $this->validateType($type);
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
        $setup = $this->normalizedSetup($decodedSetup);
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
            'contact_email' => $data['contact_email'] ?? $contact['email'] ?? $request->user()->email,
            'phone' => $data['phone'] ?? $contact['tel'] ?? null,
            'address' => $data['address'] ?? $this->addressLine($addressDetails),
            'category_key' => $data['category_key'],
            'palette_key' => $data['palette_key'] ?? 'amber-dawn',
            'setup' => $setup,
        ];
        // An account-email fallback is not a business detail supplied for matching.
        $matchingData = [...$proposed, 'contact_email' => $data['contact_email'] ?? $contact['email'] ?? null];
        $uploads = [];

        try {
            foreach (['logo' => 'pages/logos', 'banner' => 'pages/banners'] as $field => $directory) {
                if ($request->boolean($field.'_remove') || $request->hasFile($field)) {
                    $proposed[$field.'_path'] = null;
                    $proposed[$field.'_original_name'] = null;
                }
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $path = $this->storePublicWebp($file, $directory, $field);
                    $uploads[] = $path;
                    $proposed[$field.'_path'] = $path;
                    $proposed[$field.'_original_name'] = $this->originalUploadName($request, $field, $file);
                }
            }

            $save = fn (): array => DB::transaction(function () use ($request, $type, $proposed, $matchingData, $addressDetails): array {
                // Serialize new-page creation and conflict approval for the same requester.
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                $page = Page::query()->where('user_id', $user->id)->where('type', $type)->lockForUpdate()->first();
                $outcome = $page ? 'updated' : 'created';

                if ($page === null && $type === Page::TYPE_BUSINESS) {
                    $matches = $this->businessMatches->matches($matchingData);
                    $this->conflicts->cancelObsoleteSubmissions($user, $matches->pluck('page.id')->all());
                    if ($matches->count() > 1 || ($matches->count() === 1 && ! $matches->first()['page']->is_unclaimed)) {
                        $groupId = (string) Str::uuid();
                        $requests = $matches->map(fn (array $match): array => $this->claims->requestPayload(
                            $this->conflicts->submit($user, $match['page'], $proposed, $match['matched_on'], $groupId)
                        ))->all();

                        return ['save_outcome' => 'claim_conflict', 'claim_requests' => $requests, 'pending_claim' => true];
                    }
                    if ($matches->count() === 1) {
                        $page = $matches->first()['page'];
                        $page->forceFill(['user_id' => $user->id, 'is_unclaimed' => false, 'claimed_at' => now()]);
                        $outcome = 'adopted';
                    }
                }

                $page ??= new Page(['user_id' => $user->id, 'type' => $type]);
                $obsolete = $this->forms->fill($page, $proposed);
                $page->save();
                $page->ads()->update([
                    ...($outcome === 'adopted' ? ['user_id' => $user->id] : []),
                    'city' => $addressDetails['city'] ?? null,
                    'neighborhood' => $addressDetails['neighborhood'] ?? null,
                ]);
                if ($outcome === 'adopted') {
                    $this->claims->cancelCompetingRequests($page, $user);
                }

                return ['save_outcome' => $outcome, 'page' => $page, 'obsolete_media' => $obsolete];
            }, attempts: 3);

            // The name is mandatory for every match, including category-only second signals.
            // This lock also covers two users concurrently creating the same business.
            $result = $type === Page::TYPE_BUSINESS
                ? Cache::lock('business-page-save:'.hash('sha256', $this->identities->text($data['name'])), 60)->block(10,
                    fn (): array => $this->identities->withDuplicateLocks([
                        ...$matchingData, 'type' => $type, 'address' => $addressDetails, 'website' => $setup['website'],
                    ], $save))
                : $save();
        } catch (\Throwable $exception) {
            foreach ($uploads as $path) {
                $this->deletePublicUpload($path);
            }
            throw $exception;
        }

        if ($result['save_outcome'] === 'claim_conflict') {
            return ApiResponseService::success($result, 'Claim conflict sent to the administrator for review.', 202);
        }

        // A rollback must never leave the original page pointing at deleted images.
        $this->deletions->deleteMedia($result['obsolete_media']);
        $page = $result['page']->fresh(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])
            ->loadCount('ratings')->loadAvg('ratings', 'rating');

        return ApiResponseService::success([
            ...$this->payloads->page($page, withAds: true),
            'save_outcome' => $result['save_outcome'],
        ], 'Page saved.');
    }

    public function updateFeatures(Request $request, string $type)
    {
        if ($request->user()->hasRole('ai_worker')) {
            return ApiResponseService::error('Generated pages cannot enable modules.', status: 403);
        }

        $this->validateType($type);

        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.store' => ['nullable', 'boolean'],
            'features.services' => ['nullable', 'boolean'],
            'features.events' => ['nullable', 'boolean'],
            'features.price_list' => ['nullable', 'boolean'],
        ]);

        $page = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->firstOrFail();

        $features = $data['features'];
        $setup = is_array($page->setup) ? $page->setup : [];
        $setup['features'] = [
            'store' => $type === Page::TYPE_BUSINESS ? $this->booleanValue($features['store'] ?? null, false) : false,
            'services' => $type === Page::TYPE_BUSINESS ? $this->booleanValue($features['services'] ?? null, false) : false,
            'events' => $type === Page::TYPE_COMMUNITY ? $this->booleanValue($features['events'] ?? null, false) : false,
            'price_list' => $type === Page::TYPE_BUSINESS ? $this->booleanValue($features['price_list'] ?? null, false) : false,
        ];

        $page->setup = $this->normalizedSetup($setup);
        $page->save();

        return ApiResponseService::success($this->payloads->page($page->fresh(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])->loadCount('ratings')->loadAvg('ratings', 'rating'), withAds: true), 'Page saved.');
    }

    public function show(Request $request, Page $page)
    {
        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $page->load(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])
            ->loadCount('ratings')
            ->loadAvg('ratings', 'rating');

        $payload = $this->payloads->page($page, withAds: true);
        $viewer = $request->user('sanctum');
        $viewerClaim = $viewer
            ? PageClaimRequest::query()
                ->where('page_id', $page->id)
                ->where('user_id', $viewer->id)
                ->latest('created_at')
                ->latest('id')
                ->first()
            : null;
        $payload['viewer_claim'] = $viewerClaim ? [
            'id' => $viewerClaim->id,
            'status' => $viewerClaim->status,
            'created_at' => $viewerClaim->created_at?->toISOString(),
            'reviewed_at' => $viewerClaim->reviewed_at?->toISOString(),
        ] : null;

        return ApiResponseService::success($payload);
    }

    public function destroy(Request $request, Page $page)
    {
        if ($page->user_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->deletions->delete($page);

        return ApiResponseService::success(null, 'Page deleted.');
    }

    private function validateType(string $type): void
    {
        validator(['type' => $type], [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])],
        ])->validate();
    }

    private function catalogScopeForType(string $type): string
    {
        return $type === Page::TYPE_COMMUNITY
            ? CatalogTopics::SCOPE_COMMUNITY_PAGES
            : CatalogTopics::SCOPE_BUSINESS_PAGES;
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

    private function normalizedSetup(mixed $setup): array
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

    private function booleanValue(mixed $value, bool $default): bool
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
