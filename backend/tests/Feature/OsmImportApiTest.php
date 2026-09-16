<?php

namespace Tests\Feature;

use App\Models\BusinessImportClient;
use App\Models\BusinessImportMatchReview;
use App\Models\BusinessImportSource;
use App\Models\BusinessImportSourceAlias;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OsmImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['business_import.osm_public_import_enabled' => true]);
        $client = Client::factory()->asClientCredentials()->create();
        $scopes = [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE];
        BusinessImportClient::create(['oauth_client_id' => $client->getKey(), 'name' => 'OSM test', 'allowed_scopes' => $scopes, 'active' => true]);
        Passport::actingAsClient($client, $scopes);
    }

    public function test_osm_public_writes_are_disabled_by_default_while_duplicate_preview_remains_available(): void
    {
        $defaults = require config_path('business_import.php');
        $this->assertFalse($defaults['osm_public_import_enabled']);
        config(['business_import.osm_public_import_enabled' => false]);
        $payload = $this->osm('node/99');
        $this->postJson('/api/v1/business-import/businesses/duplicates', [...$payload, 'dry_run' => true])->assertOk()->assertJsonPath('data.duplicate', false);
        $this->postBusiness($payload)->assertForbidden()->assertJsonValidationErrors('source_disabled');
        $this->postJson('/api/v1/business-import/businesses/batch', [
            'client_import_id' => (string) Str::uuid(), 'businesses' => [$payload],
        ])->assertCreated()->assertJsonPath('data.items.0.status', 'source_disabled');
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
        $this->assertDatabaseCount('business_import_match_reviews', 0);
    }

    public function test_new_osm_business_keeps_unknown_source_location_category_and_complex_raw_hours_with_safe_attribution(): void
    {
        $payload = $this->osm('node/100');
        $payload['address'] = ['city' => 'Unlisted source village'];
        $payload['source']['metadata']['source_city'] = 'Unlisted source village';
        $payload['source']['metadata']['source_categories'] = [['key' => 'shop=unlisted', 'label' => 'Unlisted specialist', 'catalog_key' => null]];
        $payload['source']['metadata']['opening_hours_raw'] = 'Mo-Fr 09:00-18:00; PH off; appointment';
        $payload['source']['metadata']['attribution_url'] = 'javascript:alert(1)';
        $pageId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $this->getJson('/api/v1/pages/'.$pageId)->assertOk()
            ->assertJsonPath('data.address_details.city', 'Unlisted source village')
            ->assertJsonPath('data.category_key', null)
            ->assertJsonPath('data.source_categories.0.label', 'Unlisted specialist')
            ->assertJsonPath('data.opening_hours', [])
            ->assertJsonPath('data.opening_hours_raw', 'Mo-Fr 09:00-18:00; PH off; appointment')
            ->assertJsonPath('data.source_attributions.0.label', '© OpenStreetMap contributors')
            ->assertJsonPath('data.source_attributions.0.url', 'https://www.openstreetmap.org/copyright')
            ->assertJsonPath('data.source_attributions.0.license_url', 'https://opendatacommons.org/licenses/odbl/1-0/');
        $this->assertDatabaseHas('business_import_cities', ['provider' => 'osm_places', 'raw_value' => 'Unlisted source village']);
        $this->assertDatabaseHas('business_import_categories', ['provider' => 'osm_places', 'raw_value' => 'shop=unlisted']);
        $this->assertSame($payload['source']['metadata'], BusinessImportSource::sole()->metadata);
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $pageId);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_record_alias_adds_only_missing_details_and_never_replaces_existing_hours_or_contacts(): void
    {
        $overture = $this->overture('existing-alias', 'way/101');
        $overture['name'] = 'Established name';
        $overture['phone'] = '03-1111111';
        $overture['address'] = ['city' => 'Haifa', 'street' => 'Herzl', 'number' => '5'];
        $pageId = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $payload = $this->osm('way/101');
        $payload['name'] = 'Translated name';
        $payload['phone'] = '03-2222222';
        $payload['website'] = 'https://added.example.com';
        $payload['category_key'] = 'professionals.electricians';
        $payload['address'] = ['city' => 'Haifa', 'street' => 'Herzl', 'number' => '5', 'neighborhood' => 'Hadar'];
        $payload['opening_hours'] = [['weekday' => 'monday', 'is_open' => true, 'opens_at' => '10:00', 'closes_at' => '18:00']];
        $payload['source']['metadata']['opening_hours_raw'] = 'Mo 10:00-18:00';
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertOk()
            ->assertJsonPath('data.matches.0.id', $pageId)->assertJsonPath('data.matches.0.matched_on', ['source_alias']);
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $pageId)
            ->assertJsonPath('data.business.name', 'Established name')->assertJsonPath('data.business.phone', '03-1111111')
            ->assertJsonPath('data.business.website', 'https://added.example.com')->assertJsonPath('data.business.category_key', 'professionals.electricians')
            ->assertJsonPath('data.business.address.neighborhood', 'Hadar');
        $page = Page::findOrFail($pageId);
        $hours = $page->setup['opening_hours'];
        $this->assertSame('Mo 10:00-18:00', $page->setup['imported_opening_hours']);
        $payload['website'] = 'https://replacement.example.com';
        $payload['opening_hours'][0]['opens_at'] = '06:00';
        $payload['source']['metadata']['opening_hours_raw'] = 'Mo 06:00-18:00';
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.website', 'https://added.example.com');
        $this->assertSame($hours, $page->fresh()->setup['opening_hours']);
        $this->assertSame('Mo 10:00-18:00', $page->fresh()->setup['imported_opening_hours']);
        $this->assertCount(1, $page->fresh()->setup['imported_attributions']);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_new_osm_page_initial_public_html_contains_raw_hours_and_copyright_attribution(): void
    {
        $directory = storage_path('framework/testing/osm-public-html-'.Str::uuid());
        mkdir($directory, 0755, true);
        $index = $directory.'/index.html';
        file_put_contents($index, '<!DOCTYPE html><html lang="he"><head><meta charset="UTF-8"><title>Sveevee</title></head><body><div id="app"></div><script type="module" src="/assets/fixture.js"></script></body></html>');
        config(['seo.frontend_dist' => $directory, 'app.url' => 'https://sveevee.co.il']);
        try {
            $payload = $this->osm('node/111');
            $payload['source']['metadata']['opening_hours_raw'] = 'Mo-Fr 08:00-19:00; PH off';
            $pageId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
            $page = Page::findOrFail($pageId);
            $this->get('/en'.$page->public_path)->assertOk()
                ->assertSee('Mo-Fr 08:00-19:00; PH off')
                ->assertSee('© OpenStreetMap contributors')
                ->assertSee('href="https://www.openstreetmap.org/copyright"', false)
                ->assertSee('href="https://opendatacommons.org/licenses/odbl/1-0/"', false);
        } finally {
            unlink($index);
            rmdir($directory);
        }
    }

    public function test_existing_non_osm_hours_remain_authoritative_while_other_missing_contacts_are_added(): void
    {
        $overture = $this->overture('hours-existing', 'node/102');
        $overture['opening_hours'] = [['weekday' => 'sunday', 'is_open' => true, 'opens_at' => '08:00', 'closes_at' => '16:00']];
        $pageId = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $before = Page::findOrFail($pageId)->setup['opening_hours'];
        $payload = $this->osm('node/102');
        $payload['contact_email'] = 'added@example.com';
        $payload['source']['metadata']['opening_hours_raw'] = '24/7';
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.contact_email', 'added@example.com');
        $this->getJson('/api/v1/pages/'.$pageId)->assertOk()->assertJsonPath('data.opening_hours_raw', null);
        $this->assertSame($before, Page::findOrFail($pageId)->setup['opening_hours']);
    }

    public function test_exact_name_and_full_address_match_existing_pages_but_distinct_chain_branches_stay_separate(): void
    {
        $overture = $this->overture('first-branch');
        $overture['name'] = 'Same chain';
        $overture['phone'] = '03-3333333';
        $overture['address'] = ['city' => 'Haifa', 'street' => 'Herzl', 'number' => '5'];
        $pageId = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $payload = $this->osm('node/103');
        $payload['name'] = 'Same chain';
        $payload['phone'] = '03-3333333';
        $payload['address'] = $overture['address'];
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $pageId);
        $second = $this->osm('way/103');
        $second['name'] = 'Same chain';
        $second['phone'] = '03-3333333';
        $second['address'] = ['city' => 'Haifa', 'street' => 'Herzl', 'number' => '7'];
        $secondId = $this->postBusiness($second)->assertCreated()->json('data.business.id');
        $this->assertNotSame($pageId, $secondId);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_ambiguous_aliases_and_unconfirmed_contact_matches_are_reviewed_without_creating_or_merging(): void
    {
        $this->postBusiness($this->overture('ambiguous-one', 'node/104'))->assertCreated();
        $this->postBusiness($this->overture('ambiguous-two', 'node/104'))->assertCreated();
        $this->postBusiness($this->osm('node/104'))->assertStatus(409)->assertJsonValidationErrors('review_required')
            ->assertJsonPath('data.reason', 'ambiguous_source_alias');
        $overture = $this->overture('contact-only');
        $overture['phone'] = '03-4444444';
        $this->postBusiness($overture)->assertCreated();
        $payload = $this->osm('node/105');
        $payload['phone'] = '03-4444444';
        $this->postBusiness($payload)->assertStatus(409)->assertJsonPath('data.reason', 'unconfirmed_identity');
        $this->assertSame(2, BusinessImportMatchReview::where('provider', 'osm_places')->count());
        $this->assertDatabaseCount('pages', 3);
        $this->assertSame(0, BusinessImportSource::where('provider', 'osm_places')->count());
    }

    public function test_osm_source_ids_urls_country_and_lifecycle_must_be_consistent(): void
    {
        foreach ([
            ['source.id', 'node:106', 'source.id'], ['source.id', 'node/0', 'source.id'],
            ['source.url', 'https://www.openstreetmap.org/way/106', 'source.url'],
            ['source.url', 'https://www.openstreetmap.org/node/106?extra=1', 'source.url'],
            ['source.url', 'https://example.com/node/106', 'source.url'],
            ['source.metadata.source_id', 'node/107', 'source.metadata.source_id'],
            ['source.metadata.country', 'DE', 'source.metadata.country'],
            ['source.metadata.lifecycle_status', 'disused', 'source.metadata.lifecycle_status'],
            ['source.metadata.osm_id', '999', 'source.metadata.osm_id'],
        ] as [$field, $value, $error]) {
            $payload = $this->osm('node/106');
            data_set($payload, $field, $value);
            $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        }
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_claimed_pages_are_never_enriched_even_with_an_exact_osm_alias(): void
    {
        $pageId = $this->postBusiness($this->overture('claimed', 'relation/107'))->assertCreated()->json('data.business.id');
        $owner = User::factory()->create();
        $page = Page::findOrFail($pageId);
        $page->update(['user_id' => $owner->id, 'is_unclaimed' => false, 'claimed_at' => now()]);
        $payload = $this->osm('relation/107');
        $payload['phone'] = '03-5555555';
        $this->postBusiness($payload)->assertStatus(409)->assertJsonValidationErrors('claimed');
        $this->assertNull($page->fresh()->phone);
        $this->assertSame(0, BusinessImportSource::where('provider', 'osm_places')->count());
    }

    public function test_backfill_and_alias_resync_keep_foursquare_and_only_valid_record_level_osm_aliases(): void
    {
        $payload = $this->overture('both-aliases', 'node/108');
        $fsqId = str_repeat('a', 24);
        $payload['source']['metadata']['sources'][] = ['dataset' => 'Foursquare', 'property' => '', 'record_id' => $fsqId];
        $payload['source']['metadata']['sources'][] = ['dataset' => 'OpenStreetMap', 'property' => '/properties/phone', 'record_id' => 'node/109'];
        $payload['source']['metadata']['sources'][] = ['dataset' => 'OpenStreetMap', 'property' => '', 'record_id' => 'invalid'];
        $pageId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $this->assertDatabaseCount('business_import_source_aliases', 2);
        BusinessImportSourceAlias::where('provider', 'osm_places')->delete();
        $migration = require database_path('migrations/2026_09_16_000200_backfill_overture_openstreetmap_aliases.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('business_import_source_aliases', 2);
        $this->postJson('/api/v1/business-import/businesses/duplicates', $this->osm('node/108'))->assertOk()->assertJsonPath('data.matches.0.id', $pageId);
        $payload['source']['metadata']['sources'] = [['dataset' => 'Foursquare', 'property' => '', 'record_id' => $fsqId]];
        $this->postBusiness($payload)->assertOk();
        $this->assertDatabaseCount('business_import_source_aliases', 1);
        $this->assertSame('foursquare_places', BusinessImportSourceAlias::sole()->provider);
    }

    public function test_closure_tombstones_also_block_osm_reimport_and_changed_overture_ids_with_closed_osm_aliases(): void
    {
        $fsqId = str_repeat('b', 24);
        $payload = $this->overture('closed-osm', 'node/110');
        $payload['source']['metadata']['sources'][] = ['dataset' => 'Foursquare', 'property' => '', 'record_id' => $fsqId];
        $this->postBusiness($payload)->assertCreated();
        $this->postBusiness($this->osm('node/110'))->assertOk();
        $this->postJson('/api/v1/business-import/closed-businesses', [
            'snapshot_id' => '200001', 'release' => '2026-09-01', 'dry_run' => false,
            'businesses' => [['source_id' => $fsqId, 'country' => 'IL', 'date_closed' => '2026-08-01']],
        ])->assertOk()->assertJsonPath('data.counts.removed', 1);
        $this->postBusiness($this->osm('node/110'))->assertStatus(409)->assertJsonValidationErrors('source_closed');
        $this->postBusiness($this->overture('new-closed-osm-id', 'node/110'))->assertStatus(409)->assertJsonValidationErrors('source_closed');
        $this->postJson('/api/v1/business-import/businesses/batch', [
            'client_import_id' => (string) Str::uuid(), 'businesses' => [$this->osm('node/110')],
        ])->assertCreated()->assertJsonPath('data.items.0.status', 'source_closed');
        $this->assertDatabaseCount('pages', 0);
    }

    private function postBusiness(array $payload)
    {
        return $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function osm(string $id): array
    {
        [$type, $number] = explode('/', $id);

        return ['name' => 'OSM place '.$id, 'source' => [
            'provider' => 'osm_places', 'id' => $id, 'url' => 'https://www.openstreetmap.org/'.$id,
            'metadata' => ['source_id' => $id, 'osm_type' => $type, 'osm_id' => $number, 'country' => 'IL',
                'lifecycle_status' => 'active', 'source_categories' => [], 'original_tags' => ['name' => 'OSM place '.$id]],
        ]];
    }

    private function overture(string $id, ?string $osmAlias = null): array
    {
        return ['name' => 'Original place '.$id, 'source' => [
            'provider' => 'overture_places', 'id' => $id, 'url' => 'https://explore.overturemaps.org/?gers='.$id,
            'metadata' => ['sources' => $osmAlias === null ? [] : [['dataset' => 'OpenStreetMap', 'property' => '', 'record_id' => $osmAlias]]],
        ]];
    }
}
