<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Support\CatalogTopics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    private const DISCOVERY_LIMIT = 20;

    private const RESULT_SCOPES = ['users', 'pages', 'products', 'services', 'events', 'ads'];

    private const TOPIC_SCOPES_BY_RESULT = [
        'users' => [CatalogTopics::SCOPE_USERS],
        'pages' => [CatalogTopics::SCOPE_BUSINESS_PAGES, CatalogTopics::SCOPE_COMMUNITY_PAGES],
        'products' => [CatalogTopics::SCOPE_PRODUCTS],
        'services' => [CatalogTopics::SCOPE_SERVICES],
        'events' => [CatalogTopics::SCOPE_EVENTS],
        'ads' => [CatalogTopics::SCOPE_ADS],
    ];

    public function __construct(private readonly PayloadService $payloads) {}

    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $city = $this->nullableString($request->query('city'));
        $neighborhood = $this->nullableString($request->query('neighborhood'));
        $topic = $this->topicFromQuery($request->query('category'));
        $resultScope = $this->resultScopeFromQuery($request->query('scope'));

        if ($request->filled('scope') && ! $resultScope) {
            return ApiResponseService::validationError('Validation failed.', [
                'scope' => ['The selected search type is invalid.'],
            ]);
        }

        if ($request->filled('category') && ! $topic) {
            return ApiResponseService::validationError('Validation failed.', [
                'category' => ['The selected category is invalid.'],
            ]);
        }

        if ($topic && $resultScope && ! $this->topicMatchesResultScope($topic, $resultScope)) {
            return ApiResponseService::validationError('Validation failed.', [
                'category' => ['The selected category does not match the selected search type.'],
            ]);
        }

        $hasSearchCriteria = $term !== '' || $city || $neighborhood || $topic || $resultScope;

        if ($request->boolean('discover') && ! $hasSearchCriteria) {
            $preferredCity = $this->nullableString($request->query('preferred_city'));
            $preferredNeighborhood = $preferredCity
                ? $this->nullableString($request->query('preferred_neighborhood'))
                : null;

            return $this->discovery($preferredCity, $preferredNeighborhood);
        }

        if (! $hasSearchCriteria) {
            return ApiResponseService::success([
                'users' => [],
                'pages' => [],
                'products' => [],
                'services' => [],
                'events' => [],
                'ads' => [],
            ]);
        }

        $like = '%'.$term.'%';
        $topicKey = $topic['key'] ?? null;
        $topicKeys = $topicKey ? CatalogTopics::keysForTopic($topicKey) : [];
        $adCategories = $topicKey ? CatalogTopics::adCategoriesForTopic($topicKey) : [];
        $userTypes = $topicKey ? CatalogTopics::userTypesForTopic($topicKey) : [];

        $users = $this->shouldSearch('users', $resultScope) ? User::query()
            ->with(['profile', 'pages'])
            ->whereNull('banned_at')
            ->where('role', 'user')
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('given_name', 'like', $like)
                        ->orWhere('family_name', 'like', $like);
                });
            })
            ->when($topicKey && $userTypes === [], fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($topicKey && $userTypes !== [], function (Builder $query) use ($userTypes): void {
                $query->whereHas('profile', fn (Builder $profile) => $profile->whereIn('user_type', $userTypes));
            })
            ->when($city || $neighborhood, function (Builder $query) use ($city, $neighborhood): void {
                $query->whereHas('profile', function (Builder $profile) use ($city, $neighborhood): void {
                    $profile
                        ->when($city, fn (Builder $profile) => $profile->where('city', $city))
                        ->when($neighborhood, fn (Builder $profile) => $profile->where('neighborhood', $neighborhood));
                });
            })
            ->limit(20)
            ->get()
            ->map(fn (User $user) => $this->payloads->user($user))
            ->values() : collect();

        $pages = $this->shouldSearch('pages', $resultScope) ? Page::query()
            ->with(['user.profile'])
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->when($topicKey, fn (Builder $query) => $query->whereIn('category_key', $topicKeys))
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('public_description', 'like', $like);
                });
            })
            ->when($city, function (Builder $query, string $city): void {
                $query->where(function (Builder $query) use ($city): void {
                    $query
                        ->where('setup->address->city', $city)
                        ->orWhere('address', 'like', '%'.$city.'%');
                });
            })
            ->when($neighborhood, function (Builder $query, string $neighborhood): void {
                $query->where('setup->address->neighborhood', $neighborhood);
            })
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Page $page) => $this->compactPage($page))
            ->values() : collect();

        $products = $this->shouldSearch('products', $resultScope) ? PageProduct::query()
            ->with([
                'page' => fn ($page) => $page
                    ->with('user.profile')
                    ->withCount('ratings')
                    ->withAvg('ratings', 'rating'),
            ])
            ->when($topicKey, fn (Builder $query) => $query->whereIn('category_key', $topicKeys))
            ->when($term !== '', fn (Builder $query) => $this->whereListingText($query, $like))
            ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                $page
                    ->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageLocation($page, $city, $neighborhood);
            })
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (PageProduct $product) => $this->withCompactPage($this->payloads->product($product), $product->page))
            ->values() : collect();

        $services = $this->shouldSearch('services', $resultScope) ? PageService::query()
            ->with(['page.user.profile'])
            ->when($topicKey, fn (Builder $query) => $query->whereIn('category_key', $topicKeys))
            ->when($term !== '', fn (Builder $query) => $this->whereListingText($query, $like))
            ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                $page
                    ->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageLocation($page, $city, $neighborhood);
            })
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (PageService $service) => $this->withCompactPage($this->payloads->service($service), $service->page))
            ->values() : collect();

        $events = $this->shouldSearch('events', $resultScope) ? PageEvent::query()
            ->with(['page.user.profile'])
            ->when($topicKey, fn (Builder $query) => $query->whereIn('category_key', $topicKeys))
            ->when($term !== '', fn (Builder $query) => $this->whereListingText($query, $like))
            ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                $page
                    ->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageLocation($page, $city, $neighborhood);
            })
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(20)
            ->get()
            ->map(fn (PageEvent $event) => $this->withCompactPage($this->payloads->event($event), $event->page))
            ->values() : collect();

        $ads = $this->shouldSearch('ads', $resultScope) ? Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->when($topicKey && $adCategories === [], fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($topicKey && $adCategories !== [], fn (Builder $query) => $query->whereIn('category', $adCategories))
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('title', 'like', $like)
                        ->orWhere('text', 'like', $like);
                });
            })
            ->inLocation($city, $neighborhood)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Ad $ad) => $this->payloads->ad($ad))
            ->values() : collect();

        return ApiResponseService::success([
            'users' => $users,
            'pages' => $pages,
            'products' => $products,
            'services' => $services,
            'events' => $events,
            'ads' => $ads,
            'topic' => $topic,
            'scope' => $resultScope,
        ]);
    }

    private function discovery(?string $city, ?string $neighborhood)
    {
        $pages = $this->prioritizedDiscoveryItems(
            Page::query()
                ->with(['user.profile'])
                ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at')),
            fn (Builder $query, ?string $tierCity, ?string $tierNeighborhood): Builder => $this->inPageLocation($query, $tierCity, $tierNeighborhood),
            $city,
            $neighborhood
        )->map(fn (Page $page): array => $this->compactPage($page));

        $products = $this->prioritizedDiscoveryItems(
            PageProduct::query()
                ->with([
                    'page' => fn ($page) => $page
                        ->with('user.profile')
                        ->withCount('ratings')
                        ->withAvg('ratings', 'rating'),
                ])
                ->whereHas('page', fn (Builder $page) => $page
                    ->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'))),
            fn (Builder $query, ?string $tierCity, ?string $tierNeighborhood): Builder => $this->inRelatedPageLocation($query, $tierCity, $tierNeighborhood),
            $city,
            $neighborhood
        )->map(fn (PageProduct $product): array => $this->withCompactPage($this->payloads->product($product), $product->page));

        $services = $this->prioritizedDiscoveryItems(
            PageService::query()
                ->with(['page.user.profile'])
                ->whereHas('page', fn (Builder $page) => $page
                    ->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'))),
            fn (Builder $query, ?string $tierCity, ?string $tierNeighborhood): Builder => $this->inRelatedPageLocation($query, $tierCity, $tierNeighborhood),
            $city,
            $neighborhood
        )->map(fn (PageService $service): array => $this->withCompactPage($this->payloads->service($service), $service->page));

        $events = $this->prioritizedDiscoveryItems(
            PageEvent::query()
                ->with(['page.user.profile'])
                ->whereDate('event_date', '>=', today())
                ->whereHas('page', fn (Builder $page) => $page
                    ->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'))),
            fn (Builder $query, ?string $tierCity, ?string $tierNeighborhood): Builder => $this->inRelatedPageLocation($query, $tierCity, $tierNeighborhood),
            $city,
            $neighborhood
        )->map(fn (PageEvent $event): array => $this->withCompactPage($this->payloads->event($event), $event->page));

        $ads = $this->prioritizedDiscoveryItems(
            Ad::query()
                ->with(['user.profile', 'page'])
                ->active()
                ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at')),
            fn (Builder $query, ?string $tierCity, ?string $tierNeighborhood): Builder => $query->inLocation($tierCity, $tierNeighborhood),
            $city,
            $neighborhood
        )->map(fn (Ad $ad): array => $this->payloads->ad($ad));

        return ApiResponseService::success([
            'users' => [],
            'pages' => $pages->values(),
            'products' => $products->values(),
            'services' => $services->values(),
            'events' => $events->values(),
            'ads' => $ads->values(),
            'topic' => null,
            'scope' => null,
            'mode' => 'discovery',
            'preferred_location' => [
                'city' => $city,
                'neighborhood' => $neighborhood,
            ],
        ]);
    }

    private function prioritizedDiscoveryItems(
        Builder $query,
        callable $applyLocation,
        ?string $city,
        ?string $neighborhood
    ): Collection {
        $items = collect();

        if ($city && $neighborhood) {
            $this->appendDiscoveryTier($items, $query, $applyLocation, $city, $neighborhood);
        }

        if ($city) {
            $this->appendDiscoveryTier($items, $query, $applyLocation, $city, null);
        }

        $this->appendDiscoveryTier($items, $query, $applyLocation, null, null);

        return $items;
    }

    private function appendDiscoveryTier(
        Collection $items,
        Builder $query,
        callable $applyLocation,
        ?string $city,
        ?string $neighborhood
    ): void {
        $remaining = self::DISCOVERY_LIMIT - $items->count();

        if ($remaining <= 0) {
            return;
        }

        $tier = clone $query;

        if ($city || $neighborhood) {
            $applyLocation($tier, $city, $neighborhood);
        }

        $existingIds = $items->pluck('id')->filter()->values()->all();
        if ($existingIds !== []) {
            $tier->whereNotIn($tier->getModel()->getQualifiedKeyName(), $existingIds);
        }

        $models = $tier
            ->latest()
            ->orderByDesc($tier->getModel()->getQualifiedKeyName())
            ->limit($remaining)
            ->get();

        foreach ($models as $model) {
            $items->push($model);
        }
    }

    private function topicFromQuery(mixed $value): ?array
    {
        $value = $this->nullableString($value);

        if (! $value) {
            return null;
        }

        return CatalogTopics::findByKey($value) ?? CatalogTopics::findBySlug($value);
    }

    private function resultScopeFromQuery(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if (! $value) {
            return null;
        }

        return in_array($value, self::RESULT_SCOPES, true) ? $value : null;
    }

    private function shouldSearch(string $scope, ?string $resultScope): bool
    {
        return ! $resultScope || $resultScope === $scope;
    }

    private function topicMatchesResultScope(array $topic, string $resultScope): bool
    {
        if ($resultScope === 'ads') {
            return CatalogTopics::adCategoriesForTopic($topic['key']) !== [];
        }

        $topicScopes = $topic['scopes'] ?? [];

        foreach (self::TOPIC_SCOPES_BY_RESULT[$resultScope] ?? [] as $scope) {
            if (in_array($scope, $topicScopes, true)) {
                return true;
            }
        }

        return false;
    }

    private function whereListingText(Builder $query, string $like): void
    {
        $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('name', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }

    private function inPageLocation(Builder $query, ?string $city, ?string $neighborhood): Builder
    {
        return $query
            ->when($city, function (Builder $query, string $city): void {
                $query->where(function (Builder $query) use ($city): void {
                    $query
                        ->where('setup->address->city', $city)
                        ->orWhere('address', 'like', '%'.$city.'%');
                });
            })
            ->when($neighborhood, fn (Builder $query, string $neighborhood) => $query->where('setup->address->neighborhood', $neighborhood));
    }

    private function inRelatedPageLocation(Builder $query, ?string $city, ?string $neighborhood): Builder
    {
        return $query->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
            $this->inPageLocation($page, $city, $neighborhood);
        });
    }

    private function compactPage(Page $page): array
    {
        $setup = $page->setup ?? [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];
        $isUnclaimed = (bool) $page->is_unclaimed;
        $logoPath = $isUnclaimed ? null : $page->logo_path;
        $bannerPath = $isUnclaimed ? null : $page->banner_path;

        return [
            'id' => $page->id,
            'slug' => $page->public_slug,
            'public_path' => $page->public_path,
            'user_id' => $isUnclaimed ? null : $page->user_id,
            'type' => $page->type,
            'is_unclaimed' => $isUnclaimed,
            'name' => $page->name,
            'public_description' => $page->public_description,
            'category_key' => $page->category_key,
            'palette_key' => $page->palette_key,
            'logo_url' => $isUnclaimed ? null : $page->logo_url,
            ...$this->payloads->publicImageMeta('logo', $logoPath, $page->name.' logo', '96px'),
            'banner_url' => $isUnclaimed ? null : $page->banner_url,
            ...$this->payloads->publicImageMeta('banner', $bannerPath, $page->name, '(max-width: 700px) calc(100vw - 28px), 1180px'),
            'address' => $page->address,
            'address_details' => [
                'city' => $address['city'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? null,
            ],
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    private function withCompactPage(array $payload, ?Page $page): array
    {
        return array_merge($payload, [
            'page' => $page ? $this->compactPage($page) : null,
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
