<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
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
        $this->assertNotSame($originalParts[0]['path'], $currentParts[0]['path']);
        $currentPagePart = collect($currentParts)->first(fn (array $part) => str_contains($part['path'], 'part=pages-0001'));
        $stablePageContent = $this->sitemapResponseContent($this->get($stablePagePath)->assertOk());
        $this->assertSame($currentPagePart['content'], $stablePageContent);
        $this->assertStringContainsString($newPageUrl, $stablePageContent);

        foreach ($originalParts as $part) {
            $response = $this->get($part['path'])->assertOk();
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

        $unchangedIndex = $this->get('/sitemap.xml', ['If-Modified-Since' => $lastModified])->assertStatus(304);
        $this->assertSame('', $this->sitemapResponseContent($unchangedIndex));
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
            $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z-[a-f0-9]{12}$/', $parameters['generation'] ?? '');
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
