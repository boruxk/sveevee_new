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
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CatalogController extends Controller
{
    private const SEGMENT_LIMIT = 12;

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(
        Request $request,
        ?string $topicSlug = null
    ) {
        return $this->showCatalog($request, $topicSlug);
    }

    public function indexForCity(Request $request, string $citySlug, string $topicSlug)
    {
        return $this->showCatalog($request, $topicSlug, $citySlug);
    }

    public function indexForNeighborhood(Request $request, string $citySlug, string $neighborhoodSlug, string $topicSlug)
    {
        return $this->showCatalog($request, $topicSlug, $citySlug, $neighborhoodSlug);
    }

    private function showCatalog(
        Request $request,
        ?string $topicSlug = null,
        ?string $citySlug = null,
        ?string $neighborhoodSlug = null
    ) {
        if (! filled($topicSlug)) {
            return ApiResponseService::success(CatalogTopics::publicPayload($request->query('scope')));
        }

        $hub = CatalogTopics::findScopeHub($topicSlug);

        if ($hub) {
            $city = $this->resolveCity($citySlug);
            if (filled($citySlug) && ! $city) {
                return ApiResponseService::error('Resource not found.', status: 404);
            }

            $neighborhood = $this->resolveNeighborhood($city, $neighborhoodSlug);
            if (filled($neighborhoodSlug) && ! $neighborhood) {
                return ApiResponseService::error('Resource not found.', status: 404);
            }

            $limit = max(1, min(24, (int) $request->query('limit', self::SEGMENT_LIMIT)));
            $segments = $this->hubSegments($hub, $city, $neighborhood, $limit);
            $counts = collect($segments)
                ->map(fn (array $segment): int => $segment['count'])
                ->all();

            return ApiResponseService::success(array_merge(CatalogTopics::publicPayload($hub['scopes']), [
                'hub' => $hub,
                'city' => $city,
                'city_slug' => CatalogTopics::locationSlug($city),
                'neighborhood' => $neighborhood,
                'neighborhood_slug' => CatalogTopics::locationSlug($neighborhood),
                'title_he' => $this->hubTitleHe($hub, $city, $neighborhood),
                'description_he' => $this->hubDescriptionHe($hub, $city, $neighborhood),
                'indexable' => true,
                'total_count' => array_sum($counts),
                'counts' => $counts,
                'segments' => $segments,
                'breadcrumbs' => $this->hubBreadcrumbs($hub, $city, $neighborhood),
            ]));
        }

        $topic = CatalogTopics::findBySlug($topicSlug);

        if (! $topic) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $city = $this->resolveCity($citySlug);
        if (filled($citySlug) && ! $city) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $neighborhood = $this->resolveNeighborhood($city, $neighborhoodSlug);
        if (filled($neighborhoodSlug) && ! $neighborhood) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $limit = max(1, min(24, (int) $request->query('limit', self::SEGMENT_LIMIT)));
        $segments = $this->segments($topic, $city, $neighborhood, $limit);
        $counts = collect($segments)->map(fn (array $segment): int => $segment['count'])->all();
        $total = array_sum($counts);
        $lastModified = $this->lastModifiedFor($topic['key'], $city, $neighborhood);

        return ApiResponseService::success([
            'topic' => $topic,
            'city' => $city,
            'city_slug' => CatalogTopics::locationSlug($city),
            'neighborhood' => $neighborhood,
            'neighborhood_slug' => CatalogTopics::locationSlug($neighborhood),
            'title_he' => $this->titleHe($topic, $city, $neighborhood),
            'description_he' => $this->descriptionHe($topic, $city, $neighborhood),
            'indexable' => $total > 0,
            'total_count' => $total,
            'counts' => $counts,
            'segments' => $segments,
            'breadcrumbs' => $this->breadcrumbs($topic, $city, $neighborhood),
            'related_topics' => $this->relatedTopics($topic),
            'lastmod' => $lastModified?->toISOString(),
        ]);
    }

    private function segments(array $topic, ?string $city, ?string $neighborhood, int $limit): array
    {
        return [
            'pages' => $this->segment(
                $this->pagesQuery($topic['key'], $city, $neighborhood),
                fn (Page $page): array => $this->compactPage($page),
                $limit
            ),
            'products' => $this->segment(
                $this->productsQuery($topic['key'], $city, $neighborhood),
                fn (PageProduct $product): array => $this->itemWithPage($this->payloads->product($product), $product->page),
                $limit
            ),
            'services' => $this->segment(
                $this->servicesQuery($topic['key'], $city, $neighborhood),
                fn (PageService $service): array => $this->itemWithPage($this->payloads->service($service), $service->page),
                $limit
            ),
            'events' => $this->segment(
                $this->eventsQuery($topic['key'], $city, $neighborhood),
                fn (PageEvent $event): array => $this->itemWithPage($this->payloads->event($event), $event->page),
                $limit
            ),
            'ads' => $this->segment(
                $this->adsQuery($topic['key'], $city, $neighborhood),
                fn (Ad $ad): array => $this->payloads->ad($ad),
                $limit
            ),
            'users' => $this->segment(
                $this->usersQuery($topic['key'], $city, $neighborhood),
                fn (User $user): array => $this->payloads->user($user),
                $limit
            ),
        ];
    }

    private function hubSegments(array $hub, ?string $city, ?string $neighborhood, int $limit): array
    {
        if (! in_array(CatalogTopics::SCOPE_ADS, $hub['scopes'] ?? [], true)) {
            return [];
        }

        return [
            'ads' => $this->segment(
                $this->allAdsQuery($city, $neighborhood),
                fn (Ad $ad): array => $this->payloads->ad($ad),
                $limit
            ),
        ];
    }

    private function segment(Builder $query, Closure $mapper, int $limit): array
    {
        $count = (clone $query)->count();

        return [
            'count' => $count,
            'items' => $query->limit($limit)->get()->map($mapper)->values()->all(),
        ];
    }

    private function pagesQuery(string $topicKey, ?string $city, ?string $neighborhood): Builder
    {
        $query = Page::query()
            ->with(['user.profile'])
            ->whereIn('category_key', CatalogTopics::keysForTopic($topicKey))
            ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'))
            ->latest();

        return $this->inPageLocation($query, $city, $neighborhood);
    }

    private function productsQuery(string $topicKey, ?string $city, ?string $neighborhood): Builder
    {
        return PageProduct::query()
            ->with([
                'page' => fn ($page) => $page
                    ->with('user.profile')
                    ->withCount('ratings')
                    ->withAvg('ratings', 'rating'),
            ])
            ->whereIn('category_key', CatalogTopics::keysForTopic($topicKey))
            ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                $page->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageLocation($page, $city, $neighborhood);
            })
            ->latest();
    }

    private function servicesQuery(string $topicKey, ?string $city, ?string $neighborhood): Builder
    {
        return PageService::query()
            ->with(['page.user.profile'])
            ->whereIn('category_key', CatalogTopics::keysForTopic($topicKey))
            ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                $page->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageLocation($page, $city, $neighborhood);
            })
            ->latest();
    }

    private function eventsQuery(string $topicKey, ?string $city, ?string $neighborhood): Builder
    {
        return PageEvent::query()
            ->with(['page.user.profile'])
            ->whereIn('category_key', CatalogTopics::keysForTopic($topicKey))
            ->whereHas('page', function (Builder $page) use ($city, $neighborhood): void {
                $page->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageLocation($page, $city, $neighborhood);
            })
            ->orderBy('event_date')
            ->orderBy('event_time');
    }

    private function adsQuery(string $topicKey, ?string $city, ?string $neighborhood): Builder
    {
        $categories = CatalogTopics::adCategoriesForTopic($topicKey);

        $query = $this->allAdsQuery($city, $neighborhood);

        return $categories === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('category', $categories);
    }

    private function allAdsQuery(?string $city, ?string $neighborhood): Builder
    {
        return Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'))
            ->inLocation($city, $neighborhood)
            ->latest();
    }

    private function usersQuery(string $topicKey, ?string $city, ?string $neighborhood): Builder
    {
        $userTypes = CatalogTopics::userTypesForTopic($topicKey);

        $query = User::query()
            ->with(['profile', 'pages'])
            ->whereNull('banned_at')
            ->where('role', 'user')
            ->latest();

        if ($userTypes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('profile', function (Builder $profile) use ($userTypes, $city, $neighborhood): void {
            $profile
                ->whereIn('user_type', $userTypes)
                ->when($city, fn (Builder $profile) => $profile->where('city', $city))
                ->when($neighborhood, fn (Builder $profile) => $profile->where('neighborhood', $neighborhood));
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

    private function compactPage(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->public_slug,
            'public_path' => '/pages/'.$page->public_slug,
            'user_id' => $page->user_id,
            'type' => $page->type,
            'name' => $page->name,
            'public_description' => $page->public_description,
            'category_key' => $page->category_key,
            'logo_url' => $page->logo_url,
            ...$this->payloads->publicImageMeta('logo', $page->logo_path, $page->name.' logo', '96px'),
            'banner_url' => $page->banner_url,
            ...$this->payloads->publicImageMeta('banner', $page->banner_path, $page->name, '(max-width: 700px) calc(100vw - 28px), 1180px'),
            'address' => $page->address,
            'address_details' => $this->pageAddress($page),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    private function itemWithPage(array $payload, ?Page $page): array
    {
        return array_merge($payload, [
            'page' => $page ? $this->compactPage($page) : null,
        ]);
    }

    private function pageAddress(Page $page): array
    {
        $setup = $page->setup ?? [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return [
            'city' => $address['city'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
        ];
    }

    private function lastModifiedFor(string $topicKey, ?string $city, ?string $neighborhood): ?Carbon
    {
        return collect([
            (clone $this->pagesQuery($topicKey, $city, $neighborhood))->max('updated_at'),
            (clone $this->productsQuery($topicKey, $city, $neighborhood))->max('updated_at'),
            (clone $this->servicesQuery($topicKey, $city, $neighborhood))->max('updated_at'),
            (clone $this->eventsQuery($topicKey, $city, $neighborhood))->max('updated_at'),
            (clone $this->adsQuery($topicKey, $city, $neighborhood))->max('updated_at'),
            (clone $this->usersQuery($topicKey, $city, $neighborhood))->max('updated_at'),
        ])
            ->filter()
            ->map(fn (mixed $value): Carbon => Carbon::parse($value))
            ->sortDesc()
            ->first();
    }

    private function relatedTopics(array $topic): array
    {
        return CatalogTopics::all()
            ->filter(fn (array $candidate) => $candidate['key'] !== $topic['key'] && $candidate['group_key'] === $topic['group_key'])
            ->take(8)
            ->values()
            ->all();
    }

    private function breadcrumbs(array $topic, ?string $city, ?string $neighborhood): array
    {
        $crumbs = [];

        if ($city) {
            $crumbs[] = [
                'label' => $city,
                'path' => CatalogTopics::catalogPath($topic, $city),
            ];
        }

        if ($city && $neighborhood) {
            $crumbs[] = [
                'label' => $neighborhood,
                'path' => CatalogTopics::catalogPath($topic, $city, $neighborhood),
            ];
        }

        $crumbs[] = [
            'label' => $topic['labels']['he'],
            'path' => CatalogTopics::catalogPath($topic),
        ];

        return $crumbs;
    }

    private function titleHe(array $topic, ?string $city, ?string $neighborhood): string
    {
        return collect([$city, $neighborhood, $topic['labels']['he']])
            ->filter()
            ->implode(' - ');
    }

    private function descriptionHe(array $topic, ?string $city, ?string $neighborhood): string
    {
        $location = collect([$city, $neighborhood])->filter()->implode(', ');
        $suffix = $location ? ' באזור '.$location : '';

        return 'עסקים, מוצרים, שירותים, אירועים, מודעות ואנשים מקומיים בתחום '.$topic['labels']['he'].$suffix.' ב-sveevee.';
    }

    private function hubTitleHe(array $hub, ?string $city, ?string $neighborhood): string
    {
        return collect([$city, $neighborhood, $hub['labels']['he'] ?? $hub['labels']['en']])
            ->filter()
            ->implode(' - ');
    }

    private function hubDescriptionHe(array $hub, ?string $city, ?string $neighborhood): string
    {
        $location = collect([$city, $neighborhood])->filter()->implode(', ');
        $suffix = $location ? ' באזור '.$location : '';

        return ($hub['descriptions']['he'] ?? $hub['descriptions']['en']).$suffix;
    }

    private function hubBreadcrumbs(array $hub, ?string $city, ?string $neighborhood): array
    {
        $crumbs = [];

        if ($city) {
            $crumbs[] = [
                'label' => $city,
                'path' => $this->hubPath($hub, $city),
            ];
        }

        if ($city && $neighborhood) {
            $crumbs[] = [
                'label' => $neighborhood,
                'path' => $this->hubPath($hub, $city, $neighborhood),
            ];
        }

        $crumbs[] = [
            'label' => $hub['labels']['he'] ?? $hub['labels']['en'],
            'path' => $this->hubPath($hub),
        ];

        return $crumbs;
    }

    private function hubPath(array $hub, ?string $city = null, ?string $neighborhood = null): string
    {
        $parts = ['catalog'];

        if (filled($city)) {
            $parts[] = CatalogTopics::locationSlug($city);
        }

        if (filled($city) && filled($neighborhood)) {
            $parts[] = CatalogTopics::locationSlug($neighborhood);
        }

        $parts[] = $hub['slug'];

        return '/'.implode('/', $parts);
    }

    private function resolveCity(?string $citySlug): ?string
    {
        return filled($citySlug) ? CatalogTopics::resolveCitySlug($citySlug) : null;
    }

    private function resolveNeighborhood(?string $city, ?string $neighborhoodSlug): ?string
    {
        return filled($neighborhoodSlug) ? CatalogTopics::resolveNeighborhoodSlug($city, $neighborhoodSlug) : null;
    }
}
