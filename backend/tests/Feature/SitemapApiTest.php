<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Support\CatalogTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReadsSitemaps;
use Tests\TestCase;

class SitemapApiTest extends TestCase
{
    use ReadsSitemaps;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://sveevee.co.il');
        $this->prepareSitemapDirectory();
    }

    public function test_existing_sitemap_address_indexes_bounded_parts_without_losing_localized_pages(): void
    {
        config()->set('sitemap.max_urls', 3);
        config()->set('sitemap.batch_size', 2);
        $owner = User::factory()->create();
        $pages = [];

        for ($i = 0; $i < 5; $i++) {
            $pages[] = $this->createPage($owner, ['name' => 'Boundary business '.$i]);
        }

        $this->artisan('sitemap:generate')->assertSuccessful();
        $parts = $this->readPublishedSitemaps();
        $pageParts = array_filter($parts, fn (array $part) => str_contains($part['path'], 'part=pages-'));
        $this->assertGreaterThanOrEqual(7, count($pageParts));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(3, count($part['urls']));
            $this->assertLessThanOrEqual(52428800, strlen($part['content']));
        }

        $urls = array_merge(...array_column($parts, 'urls'));
        $this->assertSame(count($urls), count(array_unique($urls)), 'Public URLs should appear exactly once.');
        $this->assertContains('https://sveevee.co.il/users/'.$owner->public_slug, $urls);

        foreach ($pages as $page) {
            foreach (['he', 'en', 'ru', 'fr'] as $locale) {
                $this->assertSame(1, count(array_keys($urls, 'https://sveevee.co.il/'.$locale.'/business/'.$page->public_slug, true)));
            }
        }
    }

    public function test_parts_split_by_actual_utf8_xml_bytes_and_preserve_images(): void
    {
        config()->set('sitemap.max_urls', 50000);
        config()->set('sitemap.max_bytes', 6000);
        config()->set('sitemap.batch_size', 2);
        $owner = User::factory()->create();
        $pages = [];

        for ($i = 0; $i < 12; $i++) {
            $pages[] = $this->createPage($owner, [
                'name' => str_repeat('אב & <גד> ', 15).$i,
                'logo_path' => 'pages/'.str_repeat('l', 180).'.webp',
                'banner_path' => 'pages/'.str_repeat('b', 180).'.webp',
            ]);
        }

        $this->artisan('sitemap:generate')->assertSuccessful();
        $parts = $this->readPublishedSitemaps();
        $pageParts = array_filter($parts, fn (array $part) => str_contains($part['path'], 'part=pages-'));
        $this->assertGreaterThan(1, count($pageParts));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(6000, strlen($part['content']));
        }

        $urls = array_merge(...array_column($parts, 'urls'));

        foreach ($pages as $page) {
            foreach (['he', 'en', 'ru', 'fr'] as $locale) {
                $this->assertSame(1, count(array_keys($urls, 'https://sveevee.co.il/'.$locale.'/business/'.$page->public_slug, true)));
            }
        }

        $firstPagePart = reset($pageParts);
        $this->assertGreaterThan(mb_strlen($firstPagePart['content'], 'UTF-8'), strlen($firstPagePart['content']));
        $xml = $this->parseSitemap($firstPagePart['content']);
        $xml->registerXPathNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        $imageTitles = array_map('strval', $xml->xpath('//image:title'));
        $this->assertContains($pages[0]->name, $imageTitles);
        $this->assertStringContainsString('&amp;', $firstPagePart['content']);
        $this->assertStringContainsString('&lt;', $firstPagePart['content']);
    }

    public function test_regeneration_discovers_new_users_and_pages_and_keeps_previous_parts_readable(): void
    {
        $firstOwner = User::factory()->create();
        $firstPage = $this->createPage($firstOwner);
        $this->artisan('sitemap:generate')->assertSuccessful();
        $originalParts = $this->readPublishedSitemaps();
        $originalGeneration = $this->publishedGeneration();
        $originalUrls = array_merge(...array_column($originalParts, 'urls'));
        $stablePagePath = '/sitemap.xml?part=pages-0001';
        $originalPagePart = collect($originalParts)->first(fn (array $part) => str_contains($part['path'], 'part=pages-0001'));
        $this->assertSame($originalPagePart['content'], $this->sitemapResponseContent($this->get($stablePagePath)->assertOk()));

        $newOwner = User::factory()->create();
        $newPage = $this->createPage($newOwner, ['name' => 'New community', 'type' => Page::TYPE_COMMUNITY]);
        $newUserUrl = 'https://sveevee.co.il/users/'.$newOwner->public_slug;
        $newPageUrl = 'https://sveevee.co.il/he/community/'.$newPage->public_slug;
        $this->assertNotContains($newUserUrl, $originalUrls);
        $this->assertNotContains($newPageUrl, $originalUrls);

        $this->artisan('sitemap:generate')->assertSuccessful();
        $currentParts = $this->readPublishedSitemaps();
        $currentUrls = array_merge(...array_column($currentParts, 'urls'));
        $this->assertContains($newUserUrl, $currentUrls);
        $this->assertContains($newPageUrl, $currentUrls);
        $this->assertContains('https://sveevee.co.il/he/business/'.$firstPage->public_slug, $currentUrls);
        $this->assertSame(array_column($originalParts, 'path'), array_column($currentParts, 'path'));
        $currentPagePart = collect($currentParts)->first(fn (array $part) => str_contains($part['path'], 'part=pages-0001'));
        $stablePageContent = $this->sitemapResponseContent($this->get($stablePagePath)->assertOk());
        $this->assertSame($currentPagePart['content'], $stablePageContent);
        $this->assertStringContainsString($newPageUrl, $stablePageContent);

        foreach ($originalParts as $part) {
            $response = $this->get($part['path'].'&generation='.$originalGeneration)->assertOk();
            $this->assertSame($part['content'], $this->sitemapResponseContent($response));
        }

        $newPage->delete();
        $this->artisan('sitemap:generate')->assertSuccessful();
        $afterDeletion = $this->readPublishedSitemaps();
        $this->assertNotContains($newPageUrl, array_merge(...array_column($afterDeletion, 'urls')));
    }

    public function test_serving_generated_sitemaps_does_not_query_all_public_records_again(): void
    {
        $owner = User::factory()->create();
        $this->createPage($owner);
        $this->artisan('sitemap:generate')->assertSuccessful();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->readPublishedSitemaps();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+["`]?\b(?:pages|users|page_products|ads)\b/i', $query['query']);
        }
    }

    public function test_sitemap_excludes_records_that_the_public_html_routes_cannot_render(): void
    {
        $owner = User::factory()->create();
        $page = $this->createPage($owner);
        $unsupportedPage = $this->createPage($owner, ['name' => 'Unsupported page', 'type' => 'unsupported']);
        $productAttributes = ['description' => 'Public product.', 'price' => 20, 'image_path' => 'products/example.webp', 'link' => 'https://example.test/product'];
        $validProduct = $page->products()->create(['name' => 'Visible product', ...$productAttributes]);
        $emptyProduct = $page->products()->create(['name' => '', ...$productAttributes]);
        $this->artisan('sitemap:generate')->assertSuccessful();
        $parts = $this->readPublishedSitemaps();
        $urls = array_merge(...array_column($parts, 'urls'));

        foreach (['he', 'en', 'ru', 'fr'] as $locale) {
            $this->assertContains('https://sveevee.co.il/'.$locale.'/business/'.$page->public_slug, $urls);
            $this->assertContains('https://sveevee.co.il/'.$locale.'/product/'.$validProduct->public_slug, $urls);
            $this->assertNotContains('https://sveevee.co.il/'.$locale.'/community/'.$unsupportedPage->public_slug, $urls);
            $this->assertNotContains('https://sveevee.co.il/'.$locale.'/business/'.$unsupportedPage->public_slug, $urls);
            $this->assertNotContains('https://sveevee.co.il/'.$locale.'/product/'.$emptyProduct->public_slug, $urls);
        }
    }

    public function test_unknown_import_locations_keep_business_pages_without_phantom_catalog_or_market_urls(): void
    {
        $owner = User::factory()->create();
        $unknownCity = 'Unlisted Source Village';
        $unknownNeighborhood = 'Unlisted Source District';
        $unknown = $this->createPage($owner, [
            'name' => 'Imported village shop',
            'category_key' => 'professionals.electricians',
            'setup' => ['address' => ['city' => $unknownCity, 'neighborhood' => $unknownNeighborhood]],
        ]);
        $knownCity = $this->createPage($owner, [
            'name' => 'Known city shop',
            'category_key' => 'professionals.electricians',
            'setup' => ['address' => ['city' => 'Jerusalem', 'neighborhood' => $unknownNeighborhood]],
        ]);
        foreach ([$unknown, $knownCity] as $page) {
            $page->products()->create([
                'name' => 'Public local product', 'description' => 'Product description.',
                'category_key' => 'products.home_garden.furniture', 'price' => 20,
                'image_path' => 'products/example.webp', 'link' => 'https://example.test/product',
            ]);
        }
        $this->artisan('sitemap:generate')->assertSuccessful();
        $parts = $this->readPublishedSitemaps();
        $urls = array_merge(...array_column($parts, 'urls'));

        $this->assertContains('https://sveevee.co.il/catalog/electricians', $urls);
        $this->assertContains('https://sveevee.co.il/catalog/jerusalem/electricians', $urls);
        foreach (['he', 'en', 'ru', 'fr'] as $locale) {
            $this->assertContains('https://sveevee.co.il/'.$locale.'/business/'.$unknown->public_slug, $urls);
            $this->assertContains('https://sveevee.co.il/'.$locale.'/business/'.$knownCity->public_slug, $urls);
            $this->assertContains('https://sveevee.co.il/'.$locale.'/market/jerusalem', $urls);
        }
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/catalog/unlisted-source-village/', $url);
            $this->assertStringNotContainsString('/market/unlisted-source-village', $url);
            $this->assertStringNotContainsString('/catalog/jerusalem/unlisted-source-district/', $url);
        }
        $this->assertSame($unknownCity, $unknown->fresh()->setup['address']['city']);
        $this->assertSame($unknownNeighborhood, $knownCity->fresh()->setup['address']['neighborhood']);
    }

    public function test_failed_generation_keeps_the_previous_complete_sitemap_published(): void
    {
        $owner = User::factory()->create();
        $this->createPage($owner);
        $this->artisan('sitemap:generate')->assertSuccessful();
        $originalIndex = $this->sitemapResponseContent($this->get('/sitemap.xml')->assertOk());
        $originalParts = $this->readPublishedSitemaps();

        config()->set('sitemap.max_bytes', 100);
        $this->artisan('sitemap:generate')->assertFailed();

        $this->assertSame($originalIndex, $this->sitemapResponseContent($this->get('/sitemap.xml')->assertOk()));
        $this->assertSame($originalParts, $this->readPublishedSitemaps());
    }

    public function test_unknown_and_malformed_child_sitemaps_return_not_found(): void
    {
        $this->artisan('sitemap:generate')->assertSuccessful();
        $parts = $this->readPublishedSitemaps();
        parse_str((string) parse_url($parts[0]['path'], PHP_URL_QUERY), $query);
        $query['generation'] = $this->publishedGeneration();

        foreach ([
            ['generation' => $query['generation'], 'part' => 'pages-999999'],
            ['generation' => '../outside', 'part' => $query['part']],
            ['generation' => $query['generation'], 'part' => '../outside'],
            ['generation' => '19000101T000000Z-000000000000', 'part' => $query['part']],
            ['generation' => [$query['generation']], 'part' => $query['part']],
            ['generation' => $query['generation'], 'part' => [$query['part']]],
            ['generation' => ['unexpected' => $query['generation']], 'part' => ['unexpected' => $query['part']]],
            ['part' => [$query['part']]],
        ] as $invalid) {
            $this->get('/sitemap.xml?'.http_build_query($invalid))->assertNotFound();
        }
    }

    public function test_sitemap_returns_retryable_unavailable_response_before_first_generation(): void
    {
        $this->get('/sitemap.xml')->assertStatus(503)->assertHeader('Retry-After', '300');
    }

    public function test_generated_sitemap_responses_support_conditional_get_and_head(): void
    {
        $this->artisan('sitemap:generate')->assertSuccessful();
        $parts = $this->readPublishedSitemaps();
        $indexResponse = $this->get('/sitemap.xml')->assertOk();
        $indexContent = $this->sitemapResponseContent($indexResponse);
        $indexResponse->assertHeader('Content-Length', (string) strlen($indexContent));

        $lastModified = $indexResponse->headers->get('Last-Modified');
        $this->assertNotEmpty($lastModified);
        $indexEtag = $indexResponse->headers->get('ETag');
        $this->assertNotEmpty($indexEtag);

        $indexHead = $this->head('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Length', (string) strlen($indexContent));
        $this->assertSame('', $this->sitemapResponseContent($indexHead));

        $part = $parts[0];
        $childResponse = $this->get($part['path'])->assertOk();
        $etag = $childResponse->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $childResponse->assertHeader('Content-Length', (string) strlen($part['content']));
        $unchangedChild = $this->get($part['path'], ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame('', $this->sitemapResponseContent($unchangedChild));

        $childHead = $this->head($part['path'])->assertOk()
            ->assertHeader('Content-Length', (string) strlen($part['content']))
            ->assertHeader('ETag', $etag);
        $this->assertSame('', $this->sitemapResponseContent($childHead));

        $unchangedIndex = $this->get('/sitemap.xml', ['If-None-Match' => $indexEtag, 'If-Modified-Since' => $lastModified])->assertStatus(304);
        $this->assertSame('', $this->sitemapResponseContent($unchangedIndex));
    }

    public function test_stable_aliases_revalidate_after_regeneration_even_when_index_mtime_is_the_same(): void
    {
        $page = $this->createPage(User::factory()->create());
        $this->artisan('sitemap:generate')->assertSuccessful();
        $oldGeneration = $this->publishedGeneration();
        $oldIndex = $this->get('/sitemap.xml')->assertOk();
        $oldIndexEtag = $oldIndex->headers->get('ETag');
        $oldLastModified = $oldIndex->headers->get('Last-Modified');
        $oldIndexTime = filemtime(config('sitemap.directory').'/generations/'.$oldGeneration.'/index.xml');
        $oldIndexXml = $this->sitemapResponseContent($oldIndex);
        $alias = '/sitemap.xml?part=pages-0001';
        $oldPart = $this->get($alias)->assertOk();
        $oldPartEtag = $oldPart->headers->get('ETag');
        $oldPartXml = $this->sitemapResponseContent($oldPart);

        $page->forceFill(['name' => 'Updated business name'])->save();
        $this->artisan('sitemap:generate')->assertSuccessful();
        $newGeneration = $this->publishedGeneration();
        $newIndexPath = config('sitemap.directory').'/generations/'.$newGeneration.'/index.xml';
        touch($newIndexPath, $oldIndexTime);
        clearstatcache(true, $newIndexPath);

        // The index contains the same stable addresses, but the child content changed.
        $index = $this->get('/sitemap.xml', ['If-None-Match' => $oldIndexEtag, 'If-Modified-Since' => $oldLastModified])->assertOk();
        $this->assertNotSame($oldIndexEtag, $index->headers->get('ETag'));
        $this->assertSame($oldIndexXml, $this->sitemapResponseContent($index));
        $this->get('/sitemap.xml', ['If-Modified-Since' => $oldLastModified])->assertOk();

        $part = $this->get($alias, ['If-None-Match' => $oldPartEtag])->assertOk();
        $newPartEtag = $part->headers->get('ETag');
        $this->assertNotSame($oldPartEtag, $newPartEtag);
        $this->assertStringContainsString($page->public_slug, $this->sitemapResponseContent($part));
        $this->assertStringContainsString('must-revalidate', $part->headers->get('Cache-Control'));
        $this->get($alias, ['If-None-Match' => $newPartEtag])->assertStatus(304);
        $legacy = $alias.'&generation='.$oldGeneration;
        $this->assertSame($oldPartXml, $this->sitemapResponseContent($this->get($legacy)->assertOk()));
        $this->get($legacy, ['If-None-Match' => $oldPartEtag])->assertStatus(304);
    }

    public function test_stable_child_addresses_survive_pruning_of_old_generations(): void
    {
        $this->createPage(User::factory()->create());
        $this->artisan('sitemap:generate')->assertSuccessful();
        $oldGeneration = $this->publishedGeneration();
        $alias = '/sitemap.xml?part=pages-0001';
        $legacy = $alias.'&generation='.$oldGeneration;
        $this->get($legacy)->assertOk();

        $oldDirectory = config('sitemap.directory').'/generations/'.$oldGeneration;
        touch($oldDirectory, time() - 8 * 86400);
        clearstatcache(true, $oldDirectory);
        $this->artisan('sitemap:generate')->assertSuccessful();

        $this->get('/sitemap.xml')->assertOk();
        $this->get($alias)->assertOk();
        $this->get($legacy)->assertNotFound();
        $this->assertDirectoryDoesNotExist($oldDirectory);
        $this->assertNotSame($oldGeneration, $this->publishedGeneration());
    }

    public function test_lastmod_uses_known_content_dates_instead_of_sitemap_generation_time(): void
    {
        $topic = CatalogTopics::all()->first();
        $owner = User::factory()->create();
        $newerPage = $this->createPage($owner, ['category_key' => $topic['key']]);
        $olderPage = $this->createPage($owner, ['name' => 'Older business', 'category_key' => $topic['key']]);
        DB::table('pages')->where('id', $newerPage->id)->update(['updated_at' => '2026-09-01 10:00:00']);
        DB::table('pages')->where('id', $olderPage->id)->update(['updated_at' => '2026-08-25 10:00:00']);
        $this->artisan('sitemap:generate')->assertSuccessful();

        $static = $this->sitemapResponseContent($this->get('/sitemap.xml?part=static-0001')->assertOk());
        $this->assertStringNotContainsString('<lastmod>', $static);
        $pages = $this->parseSitemap($this->sitemapResponseContent($this->get('/sitemap.xml?part=pages-0001')->assertOk()));
        $this->assertSame(['2026-09-01', '2026-08-25'], array_values(array_unique(array_map('strval', $pages->xpath('//sm:url/sm:lastmod')))));

        $catalog = $this->parseSitemap($this->sitemapResponseContent($this->get('/sitemap.xml?part=catalog-0001')->assertOk()));
        $topicUrl = 'https://sveevee.co.il'.CatalogTopics::catalogPath($topic);
        $topicEntry = collect($catalog->xpath('//sm:url'))->first(fn ($entry) => (string) $entry->loc === $topicUrl);
        $this->assertNotNull($topicEntry);
        $this->assertSame('2026-09-01', (string) $topicEntry->lastmod);

        $this->travel(2)->days();
        $this->artisan('sitemap:generate')->assertSuccessful();
        $this->assertSame($static, $this->sitemapResponseContent($this->get('/sitemap.xml?part=static-0001')->assertOk()));
        $catalogAgain = $this->sitemapResponseContent($this->get('/sitemap.xml?part=catalog-0001')->assertOk());
        $this->assertSame($catalog->asXML(), $this->parseSitemap($catalogAgain)->asXML());
        $this->travelBack();
    }

    private function publishedGeneration(): string
    {
        $manifest = json_decode(file_get_contents(config('sitemap.directory').'/current.json'), true, 512, JSON_THROW_ON_ERROR);

        return $manifest['generation'];
    }

    private function createPage(User $owner, array $attributes = []): Page
    {
        return Page::query()->create(array_replace([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Visible business',
            'is_unclaimed' => false,
        ], $attributes));
    }

    /** @return array<int, array{path: string, content: string, urls: array<int, string>}> */
    private function readPublishedSitemaps(): array
    {
        $response = $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8');
        $index = $this->parseSitemap($this->sitemapResponseContent($response));
        $this->assertSame('sitemapindex', $index->getName());
        $locations = $index->xpath('/sm:sitemapindex/sm:sitemap/sm:loc');
        $this->assertNotEmpty($locations);
        $parts = [];

        foreach ($locations as $location) {
            $url = parse_url((string) $location);
            $this->assertSame('https', $url['scheme']);
            $this->assertSame('sveevee.co.il', $url['host']);
            $this->assertSame('/sitemap.xml', $url['path']);
            parse_str($url['query'] ?? '', $parameters);
            $this->assertArrayNotHasKey('generation', $parameters);
            $this->assertMatchesRegularExpression('/^(?:static|users|pages|products|ads|catalog|market)-\d+$/', $parameters['part'] ?? '');
            $path = $url['path'].'?'.$url['query'];
            $childResponse = $this->get($path)->assertOk()
                ->assertHeader('content-type', 'application/xml; charset=UTF-8');
            $content = $this->sitemapResponseContent($childResponse);
            $childResponse->assertHeader('Content-Length', (string) strlen($content));
            $child = $this->parseSitemap($content);
            $this->assertSame('urlset', $child->getName());
            $parts[] = [
                'path' => $path,
                'content' => $content,
                'urls' => array_map('strval', $child->xpath('/sm:urlset/sm:url/sm:loc')),
            ];
        }

        return $parts;
    }

    private function parseSitemap(string $content): \SimpleXMLElement
    {
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        $this->assertNotFalse($xml, 'Sitemap must be valid XML.');
        $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return $xml;
    }
}
