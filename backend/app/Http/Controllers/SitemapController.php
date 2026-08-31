<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\User;
use App\Support\CatalogTopics;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SitemapController extends Controller
{
    private const LOCALES = ['he', 'en', 'ru', 'fr'];

    public function index(): Response
    {
        $entries = collect([
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
        ]);

        collect(CatalogTopics::scopeHubs())->each(fn (array $hub) => $entries->push(
            $this->entry($hub['path'], now(), 'daily', '0.7')
        ));

        User::query()
            ->with('profile:id,user_id,city,neighborhood,photo_path,updated_at')
            ->whereNull('banned_at')
            ->where('role', 'user')
            ->where(function ($query): void {
                $query
                    ->where('name', '!=', '')
                    ->orWhere('given_name', '!=', '')
                    ->orWhere('family_name', '!=', '');
            })
            ->orderBy('id')
            ->get(['id', 'name', 'given_name', 'family_name', 'updated_at'])
            ->each(fn (User $user) => $entries->push(
                $this->entry("/users/{$user->public_slug}", $this->latestUserTimestamp($user), 'weekly', '0.6', [
                    $this->image($user->profile?->photo_url, $user->display_name),
                ])
            ));

        Page::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get(['id', 'type', 'is_unclaimed', 'name', 'logo_path', 'banner_path', 'updated_at'])
            ->each(function (Page $page) use ($entries): void {
                if ($page->type === Page::TYPE_BUSINESS) {
                    foreach (self::LOCALES as $locale) {
                        $entries->push($this->entry($this->localizedBusinessPath($page, $locale), $page->updated_at, 'weekly', '0.8', $this->pageImages($page)));
                    }

                    return;
                }

                foreach (self::LOCALES as $locale) {
                    $entries->push($this->entry($this->localizedCommunityPath($page, $locale), $page->updated_at, 'weekly', '0.8', $this->pageImages($page)));
                }
            });

        PageProduct::query()
            ->with('page:id,user_id,type,name,setup,address')
            ->whereHas('page', fn ($query) => $query
                ->managed()
                ->where('type', Page::TYPE_BUSINESS)
                ->whereHas('user', fn ($user) => $user->whereNull('banned_at')))
            ->orderBy('id')
            ->get(['id', 'page_id', 'name', 'image_path', 'updated_at'])
            ->each(function (PageProduct $product) use ($entries): void {
                foreach (self::LOCALES as $locale) {
                    $entries->push($this->entry($this->localizedProductPath($product, $locale), $product->updated_at, 'weekly', '0.72', [
                        $this->image($product->image_url, $product->name),
                    ]));
                }
            });

        Ad::query()
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get(['id', 'title', 'city', 'neighborhood', 'image_path', 'updated_at'])
            ->each(fn (Ad $ad) => $entries->push(
                $this->entry("/ads/{$ad->public_slug}", $ad->updated_at, 'daily', '0.7', [
                    $this->image($ad->image_url, $ad->title),
                ])
            ));

        $this->catalogEntries()->each(fn (array $entry) => $entries->push($entry));
        $this->marketEntries()->each(fn (array $entry) => $entries->push($entry));

        return response($this->toXml($entries->take(50000)->all()), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function catalogEntries(): Collection
    {
        $paths = [];

        CatalogTopics::all()->each(function (array $topic) use (&$paths): void {
            $paths[CatalogTopics::catalogPath($topic)] = now();
        });

        $register = function (?string $topicKey, ?string $city, ?string $neighborhood, ?Carbon $updatedAt) use (&$paths): void {
            $topic = CatalogTopics::findByKey($topicKey);

            if (! $topic) {
                return;
            }

            $candidates = [
                CatalogTopics::catalogPath($topic),
            ];

            if (filled($city)) {
                $candidates[] = CatalogTopics::catalogPath($topic, $city);
            }

            if (filled($city) && filled($neighborhood)) {
                $candidates[] = CatalogTopics::catalogPath($topic, $city, $neighborhood);
            }

            foreach ($candidates as $path) {
                $current = $paths[$path] ?? null;
                $candidateDate = $updatedAt ?: now();

                if (! $current || $candidateDate->greaterThan($current)) {
                    $paths[$path] = $candidateDate;
                }
            }
        };

        Page::query()
            ->whereNotNull('category_key')
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get(['id', 'category_key', 'setup', 'address', 'updated_at'])
            ->each(fn (Page $page) => $register(
                $page->category_key,
                $this->pageAddressValue($page, 'city'),
                $this->pageAddressValue($page, 'neighborhood'),
                $page->updated_at
            ));

        PageProduct::query()
            ->with('page:id,user_id,setup,address')
            ->whereNotNull('category_key')
            ->whereHas('page', fn ($query) => $query
                ->managed()
                ->whereHas('user', fn ($user) => $user->whereNull('banned_at')))
            ->orderBy('id')
            ->get(['id', 'page_id', 'category_key', 'updated_at'])
            ->each(fn (PageProduct $product) => $register(
                $product->category_key,
                $this->pageAddressValue($product->page, 'city'),
                $this->pageAddressValue($product->page, 'neighborhood'),
                $product->updated_at
            ));

        PageService::query()
            ->with('page:id,user_id,setup,address')
            ->whereNotNull('category_key')
            ->whereHas('page', fn ($query) => $query
                ->managed()
                ->whereHas('user', fn ($user) => $user->whereNull('banned_at')))
            ->orderBy('id')
            ->get(['id', 'page_id', 'category_key', 'updated_at'])
            ->each(fn (PageService $service) => $register(
                $service->category_key,
                $this->pageAddressValue($service->page, 'city'),
                $this->pageAddressValue($service->page, 'neighborhood'),
                $service->updated_at
            ));

        PageEvent::query()
            ->with('page:id,user_id,setup,address')
            ->whereNotNull('category_key')
            ->whereHas('page', fn ($query) => $query
                ->managed()
                ->whereHas('user', fn ($user) => $user->whereNull('banned_at')))
            ->orderBy('id')
            ->get(['id', 'page_id', 'category_key', 'updated_at'])
            ->each(fn (PageEvent $event) => $register(
                $event->category_key,
                $this->pageAddressValue($event->page, 'city'),
                $this->pageAddressValue($event->page, 'neighborhood'),
                $event->updated_at
            ));

        Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereNotNull('category')
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get(['id', 'user_id', 'page_id', 'category', 'city', 'neighborhood', 'updated_at', 'expires_at', 'status'])
            ->each(fn (Ad $ad) => $register(
                CatalogTopics::keyForAdCategory($ad->category),
                $this->adLocationValue($ad, 'city'),
                $this->adLocationValue($ad, 'neighborhood'),
                $ad->updated_at
            ));

        User::query()
            ->with('profile')
            ->whereNull('banned_at')
            ->where('role', 'user')
            ->whereHas('profile', fn ($query) => $query->whereNotNull('user_type'))
            ->orderBy('id')
            ->get(['id', 'name', 'given_name', 'family_name', 'updated_at'])
            ->each(fn (User $user) => $register(
                CatalogTopics::keyForUserType($user->profile?->user_type),
                $user->profile?->city,
                $user->profile?->neighborhood,
                $this->latestUserTimestamp($user)
            ));

        return collect($paths)
            ->map(fn (Carbon $lastModified, string $path): array => $this->entry($path, $lastModified, 'weekly', '0.65'))
            ->values();
    }

    private function marketEntries(): Collection
    {
        $paths = [];
        $register = function (?string $topicKey, ?string $city, ?Carbon $updatedAt) use (&$paths): void {
            if (! filled($city)) {
                return;
            }

            $topic = CatalogTopics::findByKey($topicKey);

            $candidates = [];
            $marketType = $topic && in_array(CatalogTopics::SCOPE_PRODUCTS, $topic['scopes'] ?? [], true)
                ? CatalogTopics::marketProductTypeForTopicKey($topic['key'])
                : null;

            foreach (self::LOCALES as $locale) {
                $candidates[] = $this->localizedMarketPath(CatalogTopics::marketPath($city), $locale);

                if ($topic && in_array(CatalogTopics::SCOPE_PRODUCTS, $topic['scopes'] ?? [], true)) {
                    $candidates[] = $this->localizedMarketPath(CatalogTopics::marketPath($city, $marketType ?: $topic), $locale);
                }
            }

            foreach ($candidates as $path) {
                $current = $paths[$path] ?? null;
                $candidateDate = $updatedAt ?: now();

                if (! $current || $candidateDate->greaterThan($current)) {
                    $paths[$path] = $candidateDate;
                }
            }
        };

        PageProduct::query()
            ->with('page:id,user_id,setup,address')
            ->whereHas('page', fn ($query) => $query
                ->managed()
                ->whereHas('user', fn ($user) => $user->whereNull('banned_at')))
            ->orderBy('id')
            ->get(['id', 'page_id', 'category_key', 'updated_at'])
            ->each(fn (PageProduct $product) => $register(
                $product->category_key,
                $this->pageAddressValue($product->page, 'city'),
                $product->updated_at
            ));

        return collect($paths)
            ->map(fn (Carbon $lastModified, string $path): array => $this->entry($path, $lastModified, 'weekly', '0.62'))
            ->values();
    }

    private function localizedMarketPath(string $path, string $locale = 'he'): string
    {
        return '/'.$locale.'/'.ltrim($path, '/');
    }

    private function localizedBusinessPath(Page $page, string $locale = 'he'): string
    {
        return "/{$locale}/business/{$page->public_slug}";
    }

    private function localizedCommunityPath(Page $page, string $locale = 'he'): string
    {
        return "/{$locale}/community/{$page->public_slug}";
    }

    private function localizedProductPath(PageProduct $product, string $locale = 'he'): string
    {
        return "/{$locale}/product/{$product->public_slug}";
    }

    private function entry(string $path, ?Carbon $lastModified, string $changeFrequency, string $priority, array $images = []): array
    {
        return [
            'loc' => $this->absoluteUrl($path),
            'lastmod' => ($lastModified ?: now())->toDateString(),
            'changefreq' => $changeFrequency,
            'priority' => $priority,
            'images' => collect($images)->filter()->values()->all(),
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

        return $user->updated_at?->greaterThan($profileUpdatedAt)
            ? $user->updated_at
            : $profileUpdatedAt;
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
            return collect(config('locations.cities', []))
                ->pluck('name')
                ->first(fn (string $city) => str_contains((string) $page->address, $city));
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

    private function toXml(array $entries): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

        foreach ($entries as $entry) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.$this->escape($entry['loc']).'</loc>';
            $xml[] = '    <lastmod>'.$this->escape($entry['lastmod']).'</lastmod>';
            $xml[] = '    <changefreq>'.$this->escape($entry['changefreq']).'</changefreq>';
            $xml[] = '    <priority>'.$this->escape($entry['priority']).'</priority>';
            foreach ($entry['images'] ?? [] as $image) {
                $xml[] = '    <image:image>';
                $xml[] = '      <image:loc>'.$this->escape($image['loc']).'</image:loc>';

                if (filled($image['title'] ?? null)) {
                    $xml[] = '      <image:title>'.$this->escape($image['title']).'</image:title>';
                }

                if (filled($image['caption'] ?? null)) {
                    $xml[] = '      <image:caption>'.$this->escape($image['caption']).'</image:caption>';
                }

                $xml[] = '    </image:image>';
            }
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return implode("\n", $xml)."\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
