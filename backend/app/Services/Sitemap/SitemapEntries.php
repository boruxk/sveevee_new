<?php

namespace App\Services\Sitemap;

use App\Models\Ad;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\User;
use App\Support\CatalogTopics;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class SitemapEntries
{
    private const LOCALES = ['he', 'en', 'ru', 'fr'];

    private array $ceilings = [];

    private array $topics = [];

    private array $canonicalTopics = [];

    private array $adTopicKeys = [];

    private array $marketTypes = [];

    private array $cities;

    public function __construct(private readonly SitemapPathStore $paths, private readonly int $batchSize)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Sitemap database batch size must be positive.');
        }

        foreach ([User::class, Page::class, PageProduct::class, PageService::class, PageEvent::class, Ad::class] as $model) {
            $this->ceilings[$model] = (int) $model::query()->max('id');
        }

        foreach (CatalogTopics::all() as $topic) {
            $this->canonicalTopics[] = $topic;
            foreach ([$topic['key'], ...($topic['aliases'] ?? [])] as $key) {
                $this->topics[$key] ??= $topic;
            }
        }

        foreach (CatalogTopics::marketProductTypes() as $type) {
            foreach ($type['topic_keys'] as $key) {
                $this->marketTypes[$key] ??= $type;
            }
        }

        $this->cities = array_column(config('locations.cities', []), 'name');
    }

    public function staticEntries(): Generator
    {
        yield from [
            $this->entry('/', now(), 'daily', '1.0', [
                $this->image('/assets/landing/hero-main-1360.v1.webp', 'Sveevee local discovery'),
                $this->image('/assets/landing/sveevee-logo-640.v1.webp', 'Sveevee logo'),
            ]),
            $this->entry('/businesses', now(), 'monthly', '0.85', [
                $this->image('/assets/landing/promo-business-hero-1360.v3.webp', 'Free business page on Sveevee'),
            ]),
            $this->entry('/communities', now(), 'monthly', '0.85', [
                $this->image('/assets/landing/promo-community-hero-1360.v3.webp', 'Free community page on Sveevee'),
            ]),
            $this->entry('/business-example-page', now(), 'monthly', '0.75', [
                $this->image('/assets/landing/example-business-banner-1440.v1.webp', 'Business example page'),
                $this->image('/assets/landing/example-business-logo-512.v1.webp', 'Business example logo'),
            ]),
            $this->entry('/community-example-page', now(), 'monthly', '0.75', [
                $this->image('/assets/landing/example-community-banner-1440.v1.webp', 'Community example page'),
                $this->image('/assets/landing/example-community-logo-512.v1.webp', 'Community example logo'),
            ]),
            $this->entry('/search', now(), 'daily', '0.8'),
            $this->entry('/privacy', now(), 'monthly', '0.3'),
            $this->entry('/terms', now(), 'monthly', '0.3'),
            $this->entry('/disclaimer', now(), 'monthly', '0.3'),
        ];

        foreach (CatalogTopics::scopeHubs() as $hub) {
            yield $this->entry($hub['path'], now(), 'daily', '0.7');
        }
    }

    public function userEntries(): Generator
    {
        $users = $this->query(User::class)
            ->with('profile:id,user_id,city,neighborhood,photo_path,updated_at')
            ->whereNull('banned_at')
            ->where('role', 'user')
            ->where(function (Builder $query): void {
                $query->where('name', '!=', '')
                    ->orWhere('given_name', '!=', '')
                    ->orWhere('family_name', '!=', '');
            })
            ->select(['id', 'name', 'given_name', 'family_name', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($users as $user) {
            yield $this->entry('/users/'.$user->public_slug, $this->latestUserTimestamp($user), 'weekly', '0.6', [
                $this->image($user->profile?->photo_url, $user->display_name),
            ]);
        }
    }

    public function pageEntries(): Generator
    {
        $pages = $this->query(Page::class)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereHas('user', fn (Builder $query) => $query->whereNull('banned_at'))
            ->select(['id', 'type', 'is_unclaimed', 'name', 'logo_path', 'banner_path', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($pages as $page) {
            $segment = $page->type === Page::TYPE_BUSINESS ? 'business' : 'community';
            $slug = $page->public_slug;
            $images = $this->pageImages($page);

            foreach (self::LOCALES as $locale) {
                yield $this->entry('/'.$locale.'/'.$segment.'/'.$slug, $page->updated_at, 'weekly', '0.8', $images);
            }
        }
    }

    public function productEntries(): Generator
    {
        $products = $this->query(PageProduct::class)
            ->with('page:id,user_id,type,name,setup,address')
            ->whereHas('page', fn (Builder $query) => $query->managed()
                ->where('type', Page::TYPE_BUSINESS)
                ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at')))
            ->select(['id', 'page_id', 'name', 'image_path', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($products as $product) {
            $slug = $product->public_slug;
            $images = [$this->image($product->image_url, $product->name)];

            foreach (self::LOCALES as $locale) {
                yield $this->entry('/'.$locale.'/product/'.$slug, $product->updated_at, 'weekly', '0.72', $images);
            }
        }
    }

    public function adEntries(): Generator
    {
        $ads = $this->query(Ad::class)
            ->active()
            ->whereHas('user', fn (Builder $query) => $query->whereNull('banned_at'))
            ->select(['id', 'title', 'city', 'neighborhood', 'image_path', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($ads as $ad) {
            yield $this->entry('/ads/'.$ad->public_slug, $ad->updated_at, 'daily', '0.7', [
                $this->image($ad->image_url, $ad->title),
            ]);
        }
    }

    public function catalogEntries(): Generator
    {
        foreach ($this->canonicalTopics as $topic) {
            $this->paths->register('catalog', CatalogTopics::catalogPath($topic), now());
        }

        $pages = $this->query(Page::class)
            ->whereNotNull('category_key')
            ->whereHas('user', fn (Builder $query) => $query->whereNull('banned_at'))
            ->select(['id', 'category_key', 'setup', 'address', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($pages as $page) {
            $this->registerCatalog($page->category_key, $this->pageAddressValue($page, 'city'), $this->pageAddressValue($page, 'neighborhood'), $page->updated_at);
        }

        foreach ([PageProduct::class, PageService::class] as $model) {
            $items = $this->query($model)
                ->with('page:id,user_id,setup,address')
                ->whereNotNull('category_key')
                ->whereHas('page', fn (Builder $query) => $query->managed()
                    ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at')))
                ->select(['id', 'page_id', 'category_key', 'updated_at'])
                ->lazyById($this->batchSize);

            foreach ($items as $item) {
                $this->registerCatalog($item->category_key, $this->pageAddressValue($item->page, 'city'), $this->pageAddressValue($item->page, 'neighborhood'), $item->updated_at);
            }
        }

        $events = $this->query(PageEvent::class)
            ->with(['page:id,user_id,setup,address,is_unclaimed', 'user:id', 'user.profile:id,user_id,city,neighborhood'])
            ->whereNotNull('category_key')
            ->publiclyVisible()
            ->select(['id', 'page_id', 'user_id', 'category_key', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($events as $event) {
            $this->registerCatalog(
                $event->category_key,
                $event->user_id ? $event->user?->profile?->city : $this->pageAddressValue($event->page, 'city'),
                $event->user_id ? $event->user?->profile?->neighborhood : $this->pageAddressValue($event->page, 'neighborhood'),
                $event->updated_at,
            );
        }

        $ads = $this->query(Ad::class)
            ->with(['user:id', 'user.profile:id,user_id,city,neighborhood', 'page:id,setup,address'])
            ->active()
            ->whereNotNull('category')
            ->whereHas('user', fn (Builder $query) => $query->whereNull('banned_at'))
            ->select(['id', 'user_id', 'page_id', 'category', 'city', 'neighborhood', 'updated_at', 'expires_at', 'status'])
            ->lazyById($this->batchSize);

        foreach ($ads as $ad) {
            if (! array_key_exists($ad->category, $this->adTopicKeys)) {
                $this->adTopicKeys[$ad->category] = CatalogTopics::keyForAdCategory($ad->category);
            }

            $this->registerCatalog($this->adTopicKeys[$ad->category], $this->adLocationValue($ad, 'city'), $this->adLocationValue($ad, 'neighborhood'), $ad->updated_at);
        }

        $users = $this->query(User::class)
            ->with('profile:id,user_id,user_type,city,neighborhood,updated_at')
            ->whereNull('banned_at')
            ->where('role', 'user')
            ->whereHas('profile', fn (Builder $query) => $query->whereNotNull('user_type'))
            ->select(['id', 'name', 'given_name', 'family_name', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($users as $user) {
            $topic = $this->topics[$user->profile?->user_type ?? ''] ?? null;
            $key = $topic && in_array(CatalogTopics::SCOPE_USERS, $topic['scopes'], true) ? $topic['key'] : null;
            $this->registerCatalog($key, $user->profile?->city, $user->profile?->neighborhood, $this->latestUserTimestamp($user));
        }

        foreach ($this->paths->entries('catalog') as $row) {
            yield $this->entry($row['path'], Carbon::parse($row['lastmod']), 'weekly', '0.65');
        }
    }

    public function marketEntries(): Generator
    {
        $products = $this->query(PageProduct::class)
            ->with('page:id,user_id,setup,address')
            ->whereHas('page', fn (Builder $query) => $query->managed()
                ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at')))
            ->select(['id', 'page_id', 'category_key', 'updated_at'])
            ->lazyById($this->batchSize);

        foreach ($products as $product) {
            $city = $this->pageAddressValue($product->page, 'city');
            if (! filled($city)) {
                continue;
            }

            $topic = $this->topics[$product->category_key ?? ''] ?? null;
            $isProductTopic = $topic && in_array(CatalogTopics::SCOPE_PRODUCTS, $topic['scopes'] ?? [], true);
            $marketType = $isProductTopic ? ($this->marketTypes[$topic['key']] ?? null) : null;

            foreach (self::LOCALES as $locale) {
                $this->paths->register('market', '/'.$locale.'/'.ltrim(CatalogTopics::marketPath($city), '/'), $product->updated_at ?: now());

                if ($isProductTopic) {
                    $this->paths->register('market', '/'.$locale.'/'.ltrim(CatalogTopics::marketPath($city, $marketType ?: $topic), '/'), $product->updated_at ?: now());
                }
            }
        }

        foreach ($this->paths->entries('market') as $row) {
            yield $this->entry($row['path'], Carbon::parse($row['lastmod']), 'weekly', '0.62');
        }
    }

    private function query(string $model): Builder
    {
        return $model::query()->where((new $model)->qualifyColumn('id'), '<=', $this->ceilings[$model]);
    }

    private function registerCatalog(?string $topicKey, ?string $city, ?string $neighborhood, ?Carbon $updatedAt): void
    {
        $topic = $this->topics[$topicKey ?? ''] ?? null;
        if (! $topic) {
            return;
        }

        $updatedAt ??= now();
        $this->paths->register('catalog', CatalogTopics::catalogPath($topic), $updatedAt);

        if (filled($city)) {
            $this->paths->register('catalog', CatalogTopics::catalogPath($topic, $city), $updatedAt);
            if (filled($neighborhood)) {
                $this->paths->register('catalog', CatalogTopics::catalogPath($topic, $city, $neighborhood), $updatedAt);
            }
        }
    }

    private function entry(string $path, ?Carbon $lastModified, string $changeFrequency, string $priority, array $images = []): array
    {
        return [
            'loc' => $this->absoluteUrl($path),
            'lastmod' => ($lastModified ?: now())->toDateString(),
            'changefreq' => $changeFrequency,
            'priority' => $priority,
            'images' => array_values(array_filter($images)),
        ];
    }

    private function image(?string $path, ?string $title = null, ?string $caption = null): ?array
    {
        if (! filled($path)) {
            return null;
        }

        return [
            'loc' => $this->absoluteUrl($path),
            'title' => $title,
            'caption' => $caption ?: $title,
        ];
    }

    private function pageImages(Page $page): array
    {
        if ($page->is_unclaimed) {
            return [];
        }

        return [
            $this->image($page->banner_url, $page->name),
            $this->image($page->logo_url, $page->name.' logo'),
        ];
    }

    private function latestUserTimestamp(User $user): ?Carbon
    {
        $profileUpdatedAt = $user->profile?->updated_at;
        if (! $profileUpdatedAt) {
            return $user->updated_at;
        }

        return $user->updated_at?->greaterThan($profileUpdatedAt) ? $user->updated_at : $profileUpdatedAt;
    }

    private function pageAddressValue(?Page $page, string $field): ?string
    {
        $setup = $page?->setup ?? [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];
        $value = trim((string) ($address[$field] ?? ''));

        if ($value !== '') {
            return $value;
        }

        if ($field === 'city' && filled($page?->address)) {
            foreach ($this->cities as $city) {
                if (str_contains((string) $page->address, $city)) {
                    return $city;
                }
            }
        }

        return null;
    }

    private function adLocationValue(Ad $ad, string $field): ?string
    {
        if (filled($ad->{$field})) {
            return $ad->{$field};
        }

        if ($ad->page_id && $ad->page) {
            return $this->pageAddressValue($ad->page, $field);
        }

        return $ad->user?->profile?->{$field};
    }

    private function absoluteUrl(?string $path): string
    {
        if (! filled($path)) {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
