<?php

namespace Tests\Feature;

use App\Models\BusinessImportCategory;
use App\Models\BusinessImportCity;
use App\Models\BusinessImportClient;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use App\Services\ImportSourceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReviewedImportCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['import_city_aliases' => ['Haifa' => ['Reviewed source town']]]);
        config(['import_category_aliases' => [
            'overture_places' => ['reviewed_activity' => 'food_catering.cafes'],
            'foursquare_places' => ['reviewed_activity' => 'professionals.electricians'],
        ]]);
        $client = Client::factory()->asClientCredentials()->create();
        $scopes = [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE];
        BusinessImportClient::create(['oauth_client_id' => $client->getKey(), 'name' => 'Reviewed catalog test', 'allowed_scopes' => $scopes, 'active' => true]);
        Passport::actingAsClient($client, $scopes);
    }

    public function test_new_import_uses_reviewed_city_and_provider_specific_category_but_retains_original_metadata(): void
    {
        $source = $this->source('overture_places', 'reviewed-source');
        $response = $this->postJson('/api/v1/business-import/businesses', [
            'name' => 'Reviewed catalog business', 'address' => ['city' => 'Reviewed source town'], 'source' => $source,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
        $response->assertCreated()->assertJsonPath('data.business.address.city', 'Haifa')
            ->assertJsonPath('data.business.category_key', 'food_catering.cafes');
        $this->assertSame($source['metadata'], BusinessImportSource::sole()->metadata);
        $this->assertSame([], Page::sole()->setup['imported_categories'] ?? []);
        $this->assertDatabaseCount('business_import_cities', 0);
        $this->assertDatabaseCount('business_import_categories', 0);
        $catalog = app(ImportSourceCatalogService::class);
        $this->assertSame('professionals.electricians', $catalog->knownCategory('foursquare_places', 'reviewed_activity'));
        $this->assertNull($catalog->knownCategory('data_gov_ckan', 'reviewed_activity'));
    }

    public function test_catalog_backfill_normalizes_a_confirmed_city_alias_and_fills_category_while_protecting_owned_pages(): void
    {
        $worker = User::where('role', 'ai_worker')->firstOrFail();
        $owner = User::factory()->create();
        $pages = [];
        foreach ([false, true] as $claimed) {
            $page = Page::create(['user_id' => $claimed ? $owner->id : $worker->id, 'created_by_user_id' => $worker->id,
                'type' => 'business', 'is_unclaimed' => ! $claimed, 'name' => 'Reviewed legacy '.(int) $claimed,
                'setup' => ['address' => ['city' => 'Reviewed source town']]]);
            $source = $this->source('overture_places', 'legacy-'.(int) $claimed);
            BusinessImportSource::create(['page_id' => $page->id, 'provider' => $source['provider'], 'source_id' => $source['id'],
                'url' => $source['url'], 'metadata' => $source['metadata']]);
            $pages[] = $page;
        }
        $before = $pages[1]->fresh()->getAttributes();
        $this->assertSame(0, Artisan::call('business-import:backfill-catalog', ['--provider' => 'overture_places']));
        $preview = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $preview['counts']['updated']);
        $this->assertSame('Reviewed source town', $pages[0]->fresh()->setup['address']['city']);
        $this->assertSame(0, Artisan::call('business-import:backfill-catalog', ['--provider' => 'overture_places', '--apply' => true]));
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $result['counts']['updated']);
        $this->assertSame(1, $result['counts']['protected']);
        $this->assertSame('Haifa', $pages[0]->fresh()->setup['address']['city']);
        $this->assertSame('food_catering.cafes', $pages[0]->fresh()->category_key);
        $this->assertSame($before, $pages[1]->fresh()->getAttributes());
    }

    public function test_mapping_command_is_bounded_read_only_by_default_and_keeps_existing_review_decisions(): void
    {
        $first = $this->city('Reviewed source town');
        $conflict = $this->city('Haifa', 'Tel Aviv');
        $unknown = $this->city('Not a verified locality');
        $before = BusinessImportCity::all()->toArray();
        $preview = $this->resolve('cities', ['--limit' => 1]);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['counts']['would_map']);
        $this->assertSame($first->id, $preview['next_after']);
        $this->assertFalse($preview['eof']);
        $this->assertSame($before, BusinessImportCity::all()->toArray());
        $result = $this->resolve('cities', ['--apply' => true]);
        $this->assertSame(1, $result['counts']['mapped']);
        $this->assertSame(1, $result['counts']['conflict']);
        $this->assertSame(1, $result['counts']['unresolved']);
        $this->assertSame('Haifa', $first->fresh()->mapped_city);
        $this->assertSame('Tel Aviv', $conflict->fresh()->mapped_city);
        $this->assertNull($unknown->fresh()->mapped_city);
        $repeat = $this->resolve('cities', ['--apply' => true]);
        $this->assertSame(0, $repeat['counts']['mapped']);
        $this->assertSame(1, $repeat['counts']['unchanged']);
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_category_mapping_preserves_source_labels_and_rejects_targets_outside_the_business_catalog(): void
    {
        config(['import_category_aliases.overture_places.invalid_target' => 'does_not_exist']);
        foreach (['reviewed_activity', 'invalid_target', 'unreviewed_activity'] as $key) {
            BusinessImportCategory::create(['provider' => 'overture_places', 'raw_value' => $key, 'label' => 'Original '.$key,
                'value_hash' => hash('sha256', $key), 'first_source_id' => 'original', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }
        $result = $this->resolve('categories', ['--apply' => true]);
        $this->assertSame(1, $result['counts']['mapped']);
        $this->assertSame(2, $result['counts']['unresolved']);
        $this->assertDatabaseHas('business_import_categories', ['raw_value' => 'reviewed_activity',
            'label' => 'Original reviewed_activity', 'mapped_category_key' => 'food_catering.cafes']);
        $this->assertDatabaseCount('business_import_categories', 3);
        $this->assertSame(1, Artisan::call('business-import:resolve-catalog', ['--kind' => 'categories', '--limit' => 9001]));
    }

    private function resolve(string $kind, array $options = []): array
    {
        $this->assertSame(0, Artisan::call('business-import:resolve-catalog', ['--kind' => $kind, ...$options]));

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function city(string $name, ?string $mapped = null): BusinessImportCity
    {
        return BusinessImportCity::create(['provider' => 'overture_places', 'raw_value' => $name, 'mapped_city' => $mapped,
            'value_hash' => hash('sha256', $name), 'first_source_id' => 'original', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    private function source(string $provider, string $id): array
    {
        return ['provider' => $provider, 'id' => $id, 'url' => 'https://explore.overturemaps.org/?gers='.$id,
            'metadata' => ['source_city' => 'Reviewed source town', 'source_categories' => [
                ['key' => 'reviewed_activity', 'label' => 'Original reviewed activity', 'catalog_key' => null],
            ]]];
    }
}
