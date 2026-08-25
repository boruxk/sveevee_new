<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageProduct;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Support\CatalogTopics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MarketController extends Controller
{
    private const LIMIT = 24;

    public function index(Request $request, string $citySlug, ?string $topicSlug = null)
    {
        $city = CatalogTopics::resolveCitySlug($citySlug);

        if (! $city) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $topic = null;

        if (filled($topicSlug)) {
            $topic = CatalogTopics::findMarketProductType($topicSlug);

            if (! $topic) {
                $topic = CatalogTopics::findBySlug($topicSlug);
            }

            if (
                ! $topic ||
                ! in_array(CatalogTopics::SCOPE_PRODUCTS, $topic['scopes'] ?? [], true) ||
                ! $this->marketTopicKeys($topic)
            ) {
                return ApiResponseService::error('Resource not found.', status: 404);
            }
        }

        $limit = max(1, min(48, (int) $request->query('limit', self::LIMIT)));
        $query = $this->productsQuery($city, $topic);
        $count = (clone $query)->count();
        $lastModified = (clone $query)->max('updated_at');
        $catalogPayload = CatalogTopics::publicPayload([CatalogTopics::SCOPE_PRODUCTS]);

        return ApiResponseService::success(array_merge($catalogPayload, [
            'city' => $city,
            'city_slug' => CatalogTopics::locationSlug($city),
            'topic' => $topic,
            'indexable' => $count > 0,
            'total_count' => $count,
            'products' => [
                'count' => $count,
                'items' => $query
                    ->limit($limit)
                    ->get()
                    ->map(fn (PageProduct $product): array => $this->itemWithPage($product))
                    ->values()
                    ->all(),
            ],
            'market_topics' => CatalogTopics::marketProductTypes(),
            'related_topics' => $this->relatedProductTopics($topic),
            'lastmod' => $lastModified ? Carbon::parse($lastModified)->toISOString() : null,
        ]));
    }

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    private function productsQuery(string $city, ?array $topic = null): Builder
    {
        $query = PageProduct::query()
            ->with(['page.user.profile'])
            ->whereHas('page', function (Builder $page) use ($city): void {
                $page->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'));
                $this->inPageCity($page, $city);
            })
            ->latest();

        if ($topic) {
            $query->whereIn('category_key', $this->marketTopicKeys($topic));
        }

        return $query;
    }

    private function itemWithPage(PageProduct $product): array
    {
        return array_merge($this->payloads->product($product), [
            'page' => $product->page ? $this->compactPage($product->page) : null,
        ]);
    }

    private function compactPage(Page $page): array
    {
        $setup = $page->setup ?? [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return [
            'id' => $page->id,
            'slug' => $page->public_slug,
            'public_path' => '/pages/'.$page->public_slug,
            'type' => $page->type,
            'name' => $page->name,
            'logo_url' => $page->logo_url,
            ...$this->payloads->publicImageMeta('logo', $page->logo_path, $page->name.' logo', '96px'),
            'address_details' => [
                'city' => $address['city'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? null,
            ],
        ];
    }

    private function inPageCity(Builder $query, string $city): Builder
    {
        return $query->where(function (Builder $query) use ($city): void {
            $query
                ->where('setup->address->city', $city)
                ->orWhere('address', 'like', '%'.$city.'%');
        });
    }

    private function relatedProductTopics(?array $topic): array
    {
        $topics = collect(CatalogTopics::marketProductTypes());

        if ($topic) {
            $topics = $topics->filter(fn (array $candidate): bool => $candidate['key'] !== $topic['key']);
        }

        return $topics
            ->take(12)
            ->values()
            ->all();
    }

    private function marketTopicKeys(array $topic): array
    {
        if (! empty($topic['topic_keys']) && is_array($topic['topic_keys'])) {
            return $topic['topic_keys'];
        }

        return isset($topic['key']) ? CatalogTopics::keysForTopic($topic['key']) : [];
    }
}
