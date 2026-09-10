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

class FoursquareImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $client = Client::factory()->asClientCredentials()->create();
        BusinessImportClient::create([
            'oauth_client_id' => $client->getKey(), 'name' => 'Foursquare test',
            'allowed_scopes' => [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE], 'active' => true,
        ]);
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
    }

    public function test_new_foursquare_places_keep_original_uncatalogued_fields_and_stable_source_identity(): void
    {
        $payload = $this->foursquare(1);
        $payload['address'] = ['city' => 'Original FSQ locality'];
        $payload['source']['metadata']['source_city'] = 'Original FSQ locality';
        $payload['source']['metadata']['source_categories'] = [['key' => '987654', 'label' => 'Original FSQ activity', 'catalog_key' => null]];
        $id = $this->postBusiness($payload)->assertCreated()
            ->assertJsonPath('data.business.category_key', null)
            ->assertJsonPath('data.business.address.city', 'Original FSQ locality')->json('data.business.id');
        $this->getJson('/api/v1/pages/'.$id)->assertOk()
            ->assertJsonPath('data.address_details.city', 'Original FSQ locality')
            ->assertJsonPath('data.source_categories.0.provider', 'foursquare_places')
            ->assertJsonPath('data.source_categories.0.label', 'Original FSQ activity');
        $this->assertDatabaseHas('business_import_cities', ['provider' => 'foursquare_places', 'raw_value' => 'Original FSQ locality']);
        $this->assertDatabaseHas('business_import_categories', ['provider' => 'foursquare_places', 'raw_value' => '987654']);
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $id);
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertOk()
            ->assertJsonPath('data.matches.0.id', $id)->assertJsonPath('data.matches.0.matched_on', ['source_id']);
        $this->assertSame($payload['source']['metadata'], BusinessImportSource::sole()->metadata);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_foursquare_source_id_url_and_metadata_ids_must_agree(): void
    {
        foreach ([
            ['source.id', 'not-a-foursquare-id', 'source.id'],
            ['source.id', 'ABCDEF012345ABCDEF012345', 'source.id'],
            ['source.url', 'https://foursquare.com/v/'.$this->fsqId(2), 'source.url'],
            ['source.url', 'https://foursquare.com/placemakers/review-place/'.$this->fsqId(3), 'source.url'],
            ['source.url', 'https://foursquare.com/placemakers/review-place/'.$this->fsqId(2).'?x=1', 'source.url'],
            ['source.url', 'https://foursquare.com/placemakers/review-place/'.$this->fsqId(2).'#fragment', 'source.url'],
            ['source.url', 'https://example.com/placemakers/review-place/'.$this->fsqId(2), 'source.url'],
            ['source.metadata.fsq_place_id', $this->fsqId(3), 'source.metadata.fsq_place_id'],
            ['source.metadata.source_id', $this->fsqId(3), 'source.metadata.source_id'],
        ] as [$field, $value, $error]) {
            $payload = $this->foursquare(2);
            data_set($payload, $field, $value);
            $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors($error);
        }
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_match_reviews', 0);
    }

    public function test_overture_record_alias_matches_before_names_and_only_missing_contacts_are_added(): void
    {
        $overture = $this->overture('alias-existing', 4);
        $overture['name'] = 'Established original name';
        $overture['phone'] = '03-1111111';
        $overture['contact_email'] = 'existing@example.com';
        $overture['socials'] = ['facebook' => 'https://facebook.com/existing'];
        $id = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $this->assertDatabaseCount('business_import_source_aliases', 1);
        $payload = $this->foursquare(4);
        $payload['phone'] = '03-2222222';
        $payload['contact_email'] = 'changed@example.com';
        $payload['website'] = 'https://added.example.com';
        $payload['socials'] = ['facebook' => 'https://facebook.com/changed', 'instagram' => 'https://instagram.com/added'];
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertOk()
            ->assertJsonPath('data.matches.0.id', $id)->assertJsonPath('data.matches.0.matched_on', ['source_alias']);
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $id)
            ->assertJsonPath('data.business.name', 'Established original name')
            ->assertJsonPath('data.business.phone', '03-1111111')
            ->assertJsonPath('data.business.contact_email', 'existing@example.com')
            ->assertJsonPath('data.business.website', 'https://added.example.com')
            ->assertJsonPath('data.business.socials.facebook', 'https://facebook.com/existing')
            ->assertJsonPath('data.business.socials.instagram', 'https://instagram.com/added');
        $payload['website'] = 'https://replacement.example.com';
        $this->patchJson('/api/v1/business-import/businesses/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.business.website', 'https://added.example.com');
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_import_sources', 2);
        $this->assertDatabaseCount('business_import_match_reviews', 0);
    }

    public function test_existing_overture_metadata_is_backfilled_and_property_level_or_invalid_aliases_are_ignored(): void
    {
        $payload = $this->overture('legacy-alias', 5);
        $payload['source']['metadata']['sources'][] = ['dataset' => 'Foursquare', 'property' => '/properties/phone', 'record_id' => $this->fsqId(6)];
        $payload['source']['metadata']['sources'][] = ['dataset' => 'Other', 'property' => '', 'record_id' => $this->fsqId(7)];
        $payload['source']['metadata']['sources'][] = ['dataset' => 'Foursquare', 'property' => '', 'record_id' => 'invalid'];
        $payload['source']['metadata']['sources'][] = ['dataset' => [], 'property' => '', 'record_id' => $this->fsqId(8)];
        $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $migration = require database_path('migrations/2026_09_10_000400_create_business_import_source_matching_tables.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('business_import_source_aliases', 1);
        $this->assertSame($this->fsqId(5), BusinessImportSourceAlias::sole()->source_id);
        $this->postJson('/api/v1/business-import/businesses/duplicates', $this->foursquare(5))
            ->assertOk()->assertJsonPath('data.matches.0.id', $id);
        $payload['source']['metadata']['sources'] = [];
        $this->postBusiness($payload)->assertOk();
        $this->assertDatabaseCount('business_import_source_aliases', 0);
    }

    public function test_ambiguous_aliases_and_changed_alias_locations_are_saved_for_review_without_merging(): void
    {
        foreach (['one', 'two'] as $suffix) {
            $this->postBusiness($this->overture('ambiguous-'.$suffix, 9))->assertCreated();
        }
        $response = $this->postBusiness($this->foursquare(9))->assertStatus(409)
            ->assertJsonValidationErrors('review_required')
            ->assertJsonPath('data.reason', 'ambiguous_source_alias');
        $review = BusinessImportMatchReview::findOrFail($response->json('data.review_id'));
        $this->assertCount(2, $review->candidates);
        $this->assertSame('pending', $review->status);
        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('business_import_sources', 2);

        $overture = $this->overture('moved-alias', 10);
        $overture['address'] = ['city' => 'Tel Aviv', 'street' => 'Original Road', 'number' => '10'];
        $id = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $payload = $this->foursquare(10);
        $payload['address'] = ['city' => 'Jerusalem', 'street' => 'Other Road', 'number' => '20'];
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertStatus(409)
            ->assertJsonPath('data.reason', 'source_alias_location_conflict');
        $this->assertSame('Tel Aviv', Page::findOrFail($id)->setup['address']['city']);
        $this->assertDatabaseCount('business_import_match_reviews', 2);
    }

    public function test_unconfirmed_contacts_are_terminal_review_in_single_and_batch_imports_and_replay_is_idempotent(): void
    {
        foreach (['one', 'two'] as $suffix) {
            $this->postBusiness([...$this->overture('contact-'.$suffix), 'phone' => '03-3333333'])->assertCreated();
        }
        $payload = [...$this->foursquare(11), 'phone' => '03-3333333'];
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertStatus(409)
            ->assertJsonValidationErrors('review_required')->assertJsonPath('data.reason', 'unconfirmed_identity');
        $review = BusinessImportMatchReview::sole();
        $firstSeen = $review->first_seen_at;
        $this->postBusiness($payload)->assertStatus(409)->assertJsonPath('data.review_id', $review->id);
        $this->assertDatabaseCount('business_import_match_reviews', 1);
        $this->assertEquals($firstSeen, $review->fresh()->first_seen_at);
        $batch = ['client_import_id' => (string) Str::uuid(), 'businesses' => [$payload, $this->foursquare(12)]];
        $items = $this->postJson('/api/v1/business-import/businesses/batch', $batch)->assertCreated()
            ->assertJsonPath('data.created_count', 1)->assertJsonPath('data.conflict_count', 1)
            ->assertJsonPath('data.items.0.status', 'review_required')
            ->assertJsonPath('data.items.0.review_id', $review->id)->json('data.items');
        $lastSeen = $review->fresh()->last_seen_at;
        $this->travel(5)->minutes();
        $this->postJson('/api/v1/business-import/businesses/batch', $batch)->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.items', $items);
        $this->assertEquals($lastSeen, $review->fresh()->last_seen_at);
        $this->assertCount(2, $review->fresh()->candidates);
        $this->assertDatabaseCount('pages', 3);
        $this->assertDatabaseCount('business_import_sources', 3);
    }

    public function test_confirmed_name_and_full_address_reuse_existing_pages_but_protect_details(): void
    {
        $overture = $this->overture('confirmed-existing');
        $overture['name'] = 'Confirmed shop';
        $overture['address'] = ['city' => 'Tel Aviv', 'street' => 'Main Road', 'number' => '10'];
        $id = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $payload = $this->foursquare(13);
        $payload['name'] = 'Confirmed shop';
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Main Road 10'];
        $payload['phone'] = '03-4444444';
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonPath('data.matches.0.id', $id);
        // The worker includes the original name/address as independent association evidence.
        $this->patchJson('/api/v1/business-import/businesses/'.$id, $payload)->assertOk()->assertJsonPath('data.business.id', $id)
            ->assertJsonPath('data.business.phone', '03-4444444')
            ->assertJsonPath('data.business.address.street', 'Main Road')
            ->assertJsonPath('data.business.address.number', '10');
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_cross_language_contact_candidates_require_review_while_confirmed_other_house_numbers_stay_separate(): void
    {
        $overture = $this->overture('translated-address');
        $overture['phone'] = '03-6666666';
        $overture['address'] = ['city' => 'Tel Aviv', 'street' => 'Herzl', 'number' => '10'];
        $id = $this->postBusiness($overture)->assertCreated()->json('data.business.id');
        $payload = $this->foursquare(17);
        $payload['phone'] = '03-6666666';
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'הרצל', 'number' => '10'];
        $this->postBusiness($payload)->assertStatus(409)->assertJsonPath('data.reason', 'unconfirmed_identity');
        $this->assertSame($id, BusinessImportMatchReview::sole()->candidates[0]['id']);
        $boundary = $this->foursquare(21);
        $boundary['phone'] = '03-6666666';
        $boundary['address'] = ['city' => 'Ramat Gan', 'street' => 'Herzl', 'number' => '10'];
        $this->postBusiness($boundary)->assertStatus(409)->assertJsonPath('data.reason', 'unconfirmed_identity');
        $payload = $this->foursquare(18);
        $payload['phone'] = '03-6666666';
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Herzl', 'number' => '20'];
        $this->postBusiness($payload)->assertCreated();
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_dry_run_preflight_reports_review_without_creating_or_updating_review_records(): void
    {
        $this->postBusiness([...$this->overture('dry-review'), 'phone' => '03-7777777'])->assertCreated();
        $payload = [...$this->foursquare(19), 'phone' => '03-7777777'];
        $this->postJson('/api/v1/business-import/businesses/duplicates', [...$payload, 'dry_run' => true])
            ->assertStatus(409)->assertJsonPath('data.review_id', null)->assertJsonValidationErrors('review_required');
        $this->assertDatabaseCount('business_import_match_reviews', 0);
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertStatus(409);
        $before = BusinessImportMatchReview::sole()->getAttributes();
        $this->travel(5)->minutes();
        $this->postJson('/api/v1/business-import/businesses/duplicates', [...$payload, 'dry_run' => true])->assertStatus(409);
        $this->assertSame($before, BusinessImportMatchReview::sole()->getAttributes());
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_an_existing_foursquare_mapping_with_newly_ambiguous_page_identity_is_saved_for_review(): void
    {
        $payload = $this->foursquare(20);
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Original Road', 'number' => '10'];
        $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $page = Page::findOrFail($id);
        // Model saves can represent legacy duplicates without going through import preflight.
        $duplicate = $page->replicate();
        $duplicate->save();
        $payload['phone'] = '03-8888888';
        $this->postBusiness($payload)->assertStatus(409)
            ->assertJsonValidationErrors('review_required')
            ->assertJsonPath('data.reason', 'ambiguous_confirmed_location');
        $this->assertSame($duplicate->id, BusinessImportMatchReview::sole()->candidates[0]['id']);
        $this->assertNull($page->fresh()->phone);
        $this->assertDatabaseCount('business_import_sources', 1);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_claimed_transferred_and_deleted_alias_pages_are_never_replaced(): void
    {
        $owner = User::factory()->create();
        foreach (['claimed' => 14, 'transferred' => 15, 'deleted' => 16] as $mode => $sourceNumber) {
            $id = $this->postBusiness($this->overture('protected-'.$mode, $sourceNumber))->assertCreated()->json('data.business.id');
            $page = Page::findOrFail($id);
            if ($mode === 'deleted') {
                $page->delete();
                $this->postBusiness($this->foursquare($sourceNumber))->assertStatus(409)->assertJsonValidationErrors('review_required');
            } else {
                $page->update(['user_id' => $owner->id, 'is_unclaimed' => $mode === 'transferred']);
                $this->postBusiness([...$this->foursquare($sourceNumber), 'phone' => '03-5555555'])
                    ->assertStatus(409)->assertJsonValidationErrors('claimed');
                $this->assertNull($page->fresh()->phone);
                $this->assertSame($owner->id, $page->fresh()->user_id);
            }
        }
        $this->assertDatabaseCount('pages', 2);
        $this->assertSame(0, BusinessImportSource::where('provider', 'foursquare_places')->count());
    }

    private function postBusiness(array $payload)
    {
        return $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function fsqId(int $number): string
    {
        return str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    private function foursquare(int $number): array
    {
        $id = $this->fsqId($number);

        return [
            'name' => 'Foursquare place '.$number,
            'source' => [
                'provider' => 'foursquare_places', 'id' => $id,
                'url' => 'https://foursquare.com/placemakers/review-place/'.$id,
                'metadata' => ['fsq_place_id' => $id, 'source_id' => $id, 'original_name' => 'Foursquare place '.$number, 'source_categories' => []],
            ],
        ];
    }

    private function overture(string $id, ?int $alias = null): array
    {
        return [
            'name' => 'Overture place '.$id,
            'source' => [
                'provider' => 'overture_places', 'id' => $id,
                'url' => 'https://explore.overturemaps.org/?feature=places.place.'.$id,
                'metadata' => ['sources' => $alias === null ? [] : [[
                    'dataset' => 'Foursquare', 'provider' => 'foursquare', 'property' => '', 'record_id' => $this->fsqId($alias),
                ]]],
            ],
        ];
    }
}
