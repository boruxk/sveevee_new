<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageProduct;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublicPageHtmlTest extends TestCase
{
    use RefreshDatabase;

    private string $dist;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://sveevee.co.il']);
        $this->dist = storage_path('framework/testing/public-page-html-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($this->dist);
        File::put($this->dist.'/index.html', <<<'HTML'
<!doctype html><html lang="he" dir="rtl"><head><meta charset="UTF-8"><title>Generic home</title><meta name="description" content="Generic home description"><meta name="robots" content="index,follow"><link rel="canonical" href="https://sveevee.co.il/"></head><body><div id="app"></div><script type="module" src="/assets/app-fixture.js"></script></body></html>
HTML);
        config(['seo.frontend_dist' => $this->dist]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->dist));
    }

    protected function prepareUrlForRequest($uri)
    {
        $url = parent::prepareUrlForRequest($uri);

        // Laravel's test helper trims the slash before making the request. Restore it
        // here so the canonical redirect is tested against the actual incoming path.
        return is_string($uri) && str_ends_with($uri, '/') ? $url.'/' : $url;
    }

    public function test_homepage_has_indexable_initial_html_and_application_schema_without_session_cookies(): void
    {
        $response = $this->get('/')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $document = $this->document($response->getContent());
        $this->assertNotSame('', trim($document->evaluate('string(//main//h1)')));
        $this->assertSame('he', $document->evaluate('string(/html/@lang)'));
        $this->assertSame('https://sveevee.co.il/', $document->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame('index,follow', $document->evaluate('string(//meta[@name="robots"]/@content)'));
        $application = collect($this->structuredData($document))->firstWhere('@type', 'WebApplication');
        $this->assertNotNull($application);
        $this->assertSame('Sveevee', $application['name']);
        $this->assertSame('https://sveevee.co.il/', $application['url']);
        $this->assertSame([], $response->headers->getCookies());
        $this->get('/', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304)->assertContent('');
    }

    public function test_new_imported_business_has_complete_public_html_in_each_language_without_an_export(): void
    {
        $description = str_repeat('This business repairs musical instruments with care. ', 8).'Unique full description ending.';
        $page = $this->page([
            'is_unclaimed' => true,
            'public_description' => $description,
            'category_key' => null,
            'setup' => [
                'address' => ['city' => 'Source Village', 'street' => 'Source Street', 'house_number' => '12'],
                'imported_categories' => [['provider' => 'foursquare_places', 'key' => 'musical_repair', 'label' => 'Source Musical Repair']],
            ],
        ]);

        foreach (['he', 'en', 'ru', 'fr'] as $locale) {
            $path = '/'.$locale.'/business/'.$page->public_slug;
            $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
            $html = $response->getContent();
            $document = $this->document($html);
            $this->assertSame($locale, $document->evaluate('string(/html/@lang)'));
            $this->assertSame($locale === 'he' ? 'rtl' : 'ltr', $document->evaluate('string(/html/@dir)'));
            $this->assertSame($page->name, $document->evaluate('string(//main//h1)'));
            $this->assertSame('https://sveevee.co.il'.$path, $document->evaluate('string(//link[@rel="canonical"]/@href)'));
            $this->assertSame('index,follow', $document->evaluate('string(//meta[@name="robots"]/@content)'));
            $this->assertStringContainsString($description, $document->evaluate('string(//main)'));
            $this->assertStringContainsString('Source Village', $document->evaluate('string(//main)'));
            $this->assertStringContainsString('Source Musical Repair', $document->evaluate('string(//main)'));
            foreach (['he', 'en', 'ru', 'fr', 'x-default'] as $alternate) {
                $targetLocale = $alternate === 'x-default' ? 'he' : $alternate;
                $this->assertSame('https://sveevee.co.il/'.$targetLocale.'/business/'.$page->public_slug,
                    $document->evaluate('string(//link[@rel="alternate" and @hreflang="'.$alternate.'"]/@href)'));
            }
            $schema = $this->structuredData($document);
            $this->assertNotEmpty($schema);
            $this->assertTrue(collect($schema)->contains(fn (array $item) => ($item['url'] ?? null) === 'https://sveevee.co.il'.$path));
            $this->assertSame([], $response->headers->getCookies());
            $this->assertFileDoesNotExist($this->dist.$path.'/index.html');
        }
    }

    public function test_community_and_product_routes_have_their_own_content_and_canonical_urls(): void
    {
        $community = $this->page(['type' => Page::TYPE_COMMUNITY, 'name' => 'Local community', 'public_description' => 'Actual community information.']);
        $communityPath = '/ru/community/'.$community->public_slug;
        $communityResponse = $this->get($communityPath)->assertOk();
        $communityDocument = $this->document($communityResponse->getContent());
        $this->assertSame('https://sveevee.co.il'.$communityPath, $communityDocument->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame('Local community', $communityDocument->evaluate('string(//main//h1)'));

        $business = $this->page();
        $product = $this->product($business, ['name' => 'Repair Kit', 'description' => 'Useful public product information.', 'price' => '29.50']);
        $productPath = '/fr/product/'.$product->public_slug;
        $response = $this->get($productPath)->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame('https://sveevee.co.il'.$productPath, $document->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame('fr', $document->evaluate('string(/html/@lang)'));
        $this->assertSame('Repair Kit', $document->evaluate('string(//main//h1)'));
        $this->assertStringContainsString('Useful public product information.', $document->evaluate('string(//main)'));
        $schema = collect($this->structuredData($document))->firstWhere('@type', 'Product');
        $this->assertNotNull($schema);
        $this->assertEquals(29.50, $schema['offers']['price']);
    }

    public function test_percent_encoded_hebrew_slug_serves_its_canonical_page_without_a_redirect_loop(): void
    {
        $page = $this->page(['name' => 'בית מוזיקה בירושלים']);
        $path = '/he/business/'.$page->public_slug;
        $encodedPath = '/he/business/'.rawurlencode($page->public_slug);
        $response = $this->get($encodedPath)->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame($page->name, $document->evaluate('string(//main//h1)'));
        $this->assertSame('https://sveevee.co.il'.$path, $document->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->get($path)->assertOk()->assertHeader('ETag', $response->headers->get('ETag'));

        $redirect = $this->get('/he/business/'.rawurlencode('שם-ישן-'.$page->id))->assertStatus(301)
            ->assertRedirect('https://sveevee.co.il'.$path);
        $this->get($redirect->headers->get('Location'))->assertOk();
    }

    public function test_unknown_source_location_and_category_remain_visible_without_broken_catalog_breadcrumbs(): void
    {
        $page = $this->page([
            'is_unclaimed' => true,
            'category_key' => 'professionals.electricians',
            'setup' => [
                'address' => ['city' => 'Unlisted Source Village', 'neighborhood' => 'Unlisted Source District'],
                'imported_categories' => [['provider' => 'overture_places', 'key' => 'specialty_repair', 'label' => 'Source Specialty Repair']],
            ],
        ]);
        $response = $this->get('/en/business/'.$page->public_slug)->assertOk();
        $document = $this->document($response->getContent());
        $body = $document->evaluate('string(//main)');
        $this->assertStringContainsString('Unlisted Source Village', $body);
        $this->assertStringContainsString('Source Specialty Repair', $body);
        $this->assertGreaterThan(0, $document->query('//a[@href="/catalog/electricians"]')->length);
        $this->assertSame(0, $document->query('//a[contains(@href,"/catalog/unlisted-source-village")]')->length);

        $page->forceFill(['setup' => [
            ...$page->setup,
            'address' => ['city' => 'Jerusalem', 'neighborhood' => 'Unlisted Source District'],
        ]])->save();
        $updated = $this->document($this->get('/en/business/'.$page->public_slug)->assertOk()->getContent());
        $this->assertGreaterThan(0, $updated->query('//a[@href="/catalog/jerusalem/electricians"]')->length);
        $this->assertSame(0, $updated->query('//a[contains(@href,"/catalog/jerusalem/unlisted-source-district")]')->length);
        $this->assertStringContainsString('Unlisted Source District', $updated->evaluate('string(//main)'));
    }

    public function test_legacy_names_ids_and_trailing_slashes_redirect_to_one_canonical_url_with_chat_queries(): void
    {
        $page = $this->page();
        $target = 'https://sveevee.co.il/he/business/'.$page->public_slug;
        foreach (['/business/'.$page->id, '/pages/'.$page->id, '/pages/old-name-'.$page->id,
            '/he/pages/'.$page->id, '/he/business/old-name-'.$page->id,
            '/he/business/'.$page->public_slug.'/'] as $oldPath) {
            $this->get($oldPath)->assertStatus(301)->assertRedirect($target);
        }
        $this->get('/business/'.$page->id.'?lang=en&pageChat=1&campaign=local%20search')->assertStatus(301)
            ->assertRedirect('https://sveevee.co.il/en/business/'.$page->public_slug.'?pageChat=1&campaign=local%20search');
        $this->get('/business/'.$page->id.'?lang=invalid&pageChat=1')->assertStatus(301)
            ->assertRedirect($target.'?pageChat=1');

        $community = $this->page(['type' => Page::TYPE_COMMUNITY]);
        $this->get('/community/'.$community->id.'?lang=ru')->assertStatus(301)
            ->assertRedirect('https://sveevee.co.il/ru/community/'.$community->public_slug);
        $product = $this->product($page, ['name' => 'Legacy product', 'price' => 20]);
        $this->get('/product/'.$product->id.'?lang=fr')->assertStatus(301)
            ->assertRedirect('https://sveevee.co.il/fr/product/'.$product->public_slug);

        $oldSlug = $page->public_slug;
        $page->forceFill(['name' => 'Renamed business'])->save();
        $this->get('/en/business/'.$oldSlug)->assertStatus(301)
            ->assertRedirect('https://sveevee.co.il/en/business/'.$page->public_slug);
    }

    public function test_missing_banned_empty_and_unclaimed_product_records_return_real_noindex_404(): void
    {
        $empty = $this->page(['name' => '']);
        $banned = $this->page();
        $banned->user->forceFill(['banned_at' => now()])->save();
        $unclaimed = $this->page(['is_unclaimed' => true]);
        $hiddenProduct = $this->product($unclaimed, ['name' => 'Hidden product', 'price' => 20]);
        $emptyProduct = $this->product($this->page(), ['name' => '', 'price' => 20]);
        foreach (['/en/business/missing-99999999', '/en/business/'.$empty->public_slug,
            '/en/business/'.$banned->public_slug, '/en/product/'.$hiddenProduct->public_slug,
            '/en/product/'.$emptyProduct->public_slug] as $path) {
            $response = $this->get($path)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex');
            $this->assertStringContainsString('noindex', $this->document($response->getContent())->evaluate('string(//meta[@name="robots"]/@content)'));
        }
    }

    public function test_missing_frontend_build_returns_retryable_503_instead_of_a_generic_success_page(): void
    {
        $page = $this->page();
        File::delete($this->dist.'/index.html');
        $this->get('/en/business/'.$page->public_slug)->assertStatus(503)->assertHeader('Retry-After', '300')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_etags_and_head_follow_live_page_and_child_changes_and_never_hide_a_new_ban(): void
    {
        $page = $this->page(['setup' => ['features' => ['services' => true]]]);
        $service = $page->services()->create(['name' => 'Instrument service', 'description' => 'Original service description.', 'image_path' => 'services/example.webp']);
        $path = '/en/business/'.$page->public_slug;
        $first = $this->get($path)->assertOk();
        $firstEtag = $first->headers->get('ETag');
        $this->assertSame('"'.hash('sha256', $first->getContent()).'"', $firstEtag);
        $this->get($path, ['If-None-Match' => $firstEtag])->assertStatus(304)->assertContent('');
        $this->head($path)->assertOk()->assertHeader('ETag', $firstEtag)->assertContent('');

        $page->forceFill(['public_description' => 'Freshly updated business information.'])->save();
        $updated = $this->get($path, ['If-None-Match' => $firstEtag])->assertOk();
        $this->assertStringContainsString('Freshly updated business information.', $updated->getContent());
        $updatedEtag = $updated->headers->get('ETag');
        $this->assertNotSame($firstEtag, $updatedEtag);

        DB::table('page_services')->where('id', $service->id)->update(['description' => 'Changed service without touching the page.']);
        $childChanged = $this->get($path, ['If-None-Match' => $updatedEtag])->assertOk();
        $this->assertStringContainsString('Changed service without touching the page.', $childChanged->getContent());
        $this->assertNotSame($updatedEtag, $childChanged->headers->get('ETag'));

        $page->user->forceFill(['banned_at' => now()])->save();
        $this->get($path, ['If-None-Match' => $childChanged->headers->get('ETag')])->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_public_html_is_identical_for_signed_in_visitors_and_contains_no_account_data_or_cookies(): void
    {
        $owner = User::factory()->create(['email' => 'private-owner@example.test']);
        $page = $this->page(['user_id' => $owner->id, 'contact_email' => 'public-contact@example.test']);
        $path = '/en/business/'.$page->public_slug;
        $anonymous = $this->get($path)->assertOk();
        $this->actingAs($owner);
        $authenticated = $this->get($path)->assertOk();
        $this->assertSame($anonymous->getContent(), $authenticated->getContent());
        $this->assertSame($anonymous->headers->get('ETag'), $authenticated->headers->get('ETag'));
        $this->assertStringNotContainsString('private-owner@example.test', $authenticated->getContent());
        $this->assertStringContainsString('public-contact@example.test', $authenticated->getContent());
        $this->assertSame([], $authenticated->headers->getCookies());
        $this->assertStringContainsString('public', $authenticated->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('csrf', strtolower($authenticated->getContent()));
    }

    public function test_untrusted_names_json_and_websites_cannot_inject_html_or_regex_references(): void
    {
        $name = 'Cash $1 </script><script id="injected">alert(1)</script>';
        $page = $this->page(['name' => $name, 'setup' => ['website' => 'javascript:alert(2)']]);
        $response = $this->get('/en/business/'.$page->public_slug)->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame($name, $document->evaluate('string(//main//h1)'));
        $this->assertSame(0, $document->query('//script[@id="injected"]')->length);
        $this->assertSame(0, $document->query('//a[starts-with(@href,"javascript:")]')->length);
        $this->assertStringNotContainsString('javascript:alert(2)', $response->getContent());
        $schema = collect($this->structuredData($document))->firstWhere('@type', 'LocalBusiness');
        $this->assertNotNull($schema);
        $this->assertSame($name, $schema['name']);
    }

    public function test_public_html_limits_related_offers_and_events_to_the_visible_preview(): void
    {
        $business = $this->page(['setup' => ['features' => ['price_list' => true, 'store' => true, 'services' => true]]]);
        for ($i = 0; $i < 16; $i++) {
            $business->prices()->create(['name' => 'Price item '.$i, 'price' => $i + 1]);
            $this->product($business, ['name' => 'Product item '.$i, 'price' => $i + 1]);
            $business->services()->create(['name' => 'Service item '.$i, 'description' => 'Service preview.', 'image_path' => 'services/example.webp']);
        }
        $response = $this->get('/en/business/'.$business->public_slug)->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame(12, $document->query('//section[h2="Price list"]//li')->length);
        $this->assertSame(8, $document->query('//section[h2="Products"]//li')->length);
        $this->assertSame(8, $document->query('//section[h2="Services"]//li')->length);

        $community = $this->page(['type' => Page::TYPE_COMMUNITY, 'setup' => ['features' => ['events' => true]]]);
        for ($i = 0; $i < 12; $i++) {
            $community->events()->create(['name' => 'Community event '.$i, 'description' => 'Event details.', 'event_date' => now()->addDays($i + 1)->toDateString(), 'event_time' => '14:00', 'image_path' => 'events/example.webp', 'address' => 'Public event address']);
        }
        $communityResponse = $this->get('/en/community/'.$community->public_slug)->assertOk();
        $this->assertSame(8, $this->document($communityResponse->getContent())->query('//section[h2="Events"]//li')->length);
    }

    private function page(array $attributes = []): Page
    {
        return Page::query()->create(array_replace([
            'user_id' => User::factory()->create()->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Public Music Studio',
            'public_description' => 'Public studio information.',
            'setup' => ['address' => ['city' => 'Jerusalem']],
        ], $attributes));
    }

    private function product(Page $page, array $attributes = []): PageProduct
    {
        return $page->products()->create(array_replace([
            'name' => 'Public product',
            'description' => 'Public product description.',
            'price' => 20,
            'image_path' => 'products/example.webp',
            'link' => 'https://example.test/product',
        ], $attributes));
    }

    private function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function structuredData(DOMXPath $document): array
    {
        $json = $document->evaluate('string(//script[@type="application/ld+json" and @data-sveevee-prerender])');
        $this->assertNotSame('', $json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
