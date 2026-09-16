<?php

namespace Tests\Feature;

use App\Models\BusinessImportCategory;
use App\Models\BusinessImportCity;
use App\Models\BusinessImportClient;
use App\Models\BusinessImportMatchReview;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use App\Services\FoursquareImportMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BusinessImportCatalogBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $client = Client::factory()->asClientCredentials()->create();
        $scopes = [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE];
        BusinessImportClient::create(['oauth_client_id' => $client->getKey(), 'name' => 'Catalog backfill test', 'allowed_scopes' => $scopes, 'active' => true]);
        Passport::actingAsClient($client, $scopes);
    }

    public function test_confirmed_foursquare_alias_adds_missing_address_and_category_without_replacing_existing_content(): void
    {
        $page = $this->page(['name' => 'Original name', 'public_description' => 'Original description']);
        $overture = $this->association($page, 'overture_places', 'original-1', ['sources' => [
            ['dataset' => 'Foursquare', 'property' => '', 'record_id' => $this->id(1)],
        ]]);
        app(FoursquareImportMatchingService::class)->syncAliases($overture);
        $payload = $this->payload(1, 'Unknown village');
        $payload['public_description'] = 'Another source description';
        $payload['category_key'] = 'food_catering.cafes';
        $payload['address'] += ['street' => 'Source Street', 'number' => '7', 'neighborhood' => 'Unknown district'];
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $page->id)
            ->assertJsonPath('data.business.name', 'Original name')->assertJsonPath('data.business.public_description', 'Original description')
            ->assertJsonPath('data.business.category_key', 'food_catering.cafes')->assertJsonPath('data.business.address.city', 'Unknown village')
            ->assertJsonPath('data.business.address.number', '7')->assertJsonPath('data.business.address.neighborhood', 'Unknown district');
        $payload['category_key'] = 'professionals.electricians';
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.category_key', 'food_catering.cafes');
        $this->assertDatabaseHas('business_import_cities', ['provider' => 'foursquare_places', 'raw_value' => 'Unknown village']);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_persisted_match_reviews_aggregate_unknown_labels_but_dry_run_does_not_write(): void
    {
        $page = $this->page(['phone' => '03-1234567']);
        $payload = $this->payload(2, 'Unlisted source town');
        $payload['phone'] = '03-1234567';
        $this->postJson('/api/v1/business-import/businesses/duplicates', [...$payload, 'dry_run' => true])->assertStatus(409);
        $this->assertDatabaseCount('business_import_match_reviews', 0);
        $this->assertDatabaseCount('business_import_cities', 0);
        $this->assertDatabaseCount('business_import_categories', 0);
        $this->postBusiness($payload)->assertStatus(409)->assertJsonValidationErrors('review_required');
        $this->assertDatabaseCount('business_import_match_reviews', 1);
        $this->assertDatabaseHas('business_import_cities', ['raw_value' => 'Unlisted source town', 'example_page_id' => null]);
        $this->assertDatabaseHas('business_import_categories', ['raw_value' => 'unmapped_activity', 'example_page_id' => null]);
        $this->assertNull($page->fresh()->category_key);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_review_uses_worker_canonical_city_instead_of_treating_a_known_translated_alias_as_unknown(): void
    {
        $this->page(['phone' => '03-2222222']);
        $payload = $this->payload(3, 'Tel Aviv');
        $payload['phone'] = '03-2222222';
        $payload['source']['metadata']['source_city'] = 'תל אביב-יפו';
        $this->postBusiness($payload)->assertStatus(409)->assertJsonValidationErrors('review_required');
        $this->assertDatabaseCount('business_import_cities', 0);
        $this->assertDatabaseCount('business_import_categories', 1);
    }

    public function test_source_backfill_defaults_to_dry_run_then_fills_missing_foursquare_fields_and_public_unknown_labels_idempotently(): void
    {
        $page = $this->page(['name' => 'Preserved legacy name']);
        $metadata = $this->metadata('Legacy unknown town');
        $metadata['original_record'] = ['locality' => 'Legacy unknown town', 'address' => 'Source Road', 'house_number' => '8'];
        $metadata['source_categories'][] = ['key' => 'cafe', 'label' => 'Cafe', 'catalog_key' => 'food_catering.cafes'];
        $source = $this->association($page, 'foursquare_places', $this->id(4), $metadata);
        $before = $page->fresh()->getAttributes();
        $preview = $this->command('foursquare_places');
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['counts']['updated']);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertDatabaseCount('business_import_cities', 0);
        $this->assertDatabaseCount('business_import_categories', 0);
        $applied = $this->command('foursquare_places', apply: true);
        $this->assertSame($source->id, $applied['next_after']);
        $this->assertTrue($applied['eof']);
        $this->getJson('/api/v1/pages/'.$page->id)->assertOk()
            ->assertJsonPath('data.name', 'Preserved legacy name')->assertJsonPath('data.category_key', 'food_catering.cafes')
            ->assertJsonPath('data.address_details.city', 'Legacy unknown town')->assertJsonPath('data.address_details.number', '8')
            ->assertJsonPath('data.source_categories.0.label', 'Original source activity');
        $after = $page->fresh()->getAttributes();
        $firstSeen = BusinessImportCategory::sole()->first_seen_at;
        $this->travel(5)->minutes();
        $repeat = $this->command('foursquare_places', apply: true);
        $this->assertSame(0, $repeat['counts']['updated']);
        $this->assertSame($after, $page->fresh()->getAttributes());
        $this->assertEquals($firstSeen, BusinessImportCategory::sole()->first_seen_at);
        $this->assertDatabaseCount('business_import_cities', 1);
        $this->assertDatabaseCount('business_import_categories', 1);
        $this->assertSame($metadata, $source->fresh()->metadata);
    }

    public function test_legacy_overture_taxonomy_backfills_public_fallback_without_needing_source_descriptors(): void
    {
        $page = $this->page();
        $this->association($page, 'overture_places', 'legacy-taxonomy', [
            'address' => ['locality' => 'Old source city'], 'taxonomy' => ['primary' => 'unlisted_specialist', 'alternates' => ['other_old_activity']],
        ]);
        $before = $page->fresh()->getAttributes();
        $this->assertSame(1, $this->command('overture_places')['counts']['updated']);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertDatabaseCount('business_import_categories', 0);
        $this->command('overture_places', apply: true);
        $this->assertDatabaseCount('business_import_categories', 2);
        $this->assertDatabaseCount('business_import_cities', 1);
        $this->getJson('/api/v1/pages/'.$page->id)->assertOk()->assertJsonPath('data.source_categories.0.label', 'unlisted specialist')
            ->assertJsonPath('data.address_details.city', 'Old source city');
    }

    public function test_claimed_and_transferred_pages_are_untouched_but_their_original_source_labels_are_aggregated(): void
    {
        $owner = User::factory()->create();
        $claimed = $this->page(['user_id' => $owner->id, 'is_unclaimed' => false, 'name' => 'Owner text', 'setup' => ['custom' => 'keep']]);
        $transferred = $this->page(['user_id' => $owner->id, 'is_unclaimed' => true]);
        $before = [$claimed->fresh()->getAttributes(), $transferred->fresh()->getAttributes()];
        $this->association($claimed, 'foursquare_places', $this->id(5), $this->metadata('Claimed raw town'));
        $this->association($transferred, 'foursquare_places', $this->id(6), $this->metadata('Transferred raw town'));
        $result = $this->command('foursquare_places', apply: true);
        $this->assertSame(2, $result['counts']['protected']);
        $this->assertSame($before[0], $claimed->fresh()->getAttributes());
        $this->assertSame($before[1], $transferred->fresh()->getAttributes());
        $this->assertDatabaseCount('business_import_cities', 2);
        $this->assertDatabaseCount('business_import_categories', 1);
    }

    public function test_address_conflicts_preserve_the_page_while_retaining_source_facts_for_comparison(): void
    {
        $page = $this->page(['setup' => ['address' => ['city' => 'Haifa', 'street' => 'Original Road', 'number' => '2']]]);
        $source = $this->association($page, 'foursquare_places', $this->id(7), $this->metadata('Different source town'));
        $before = $page->fresh()->getAttributes();
        $result = $this->command('foursquare_places', apply: true);
        $this->assertSame(1, $result['counts']['conflict']);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertDatabaseHas('business_import_cities', ['raw_value' => 'Different source town', 'example_page_id' => $page->id]);
        $this->assertSame($page->id, $source->fresh()->page_id);
    }

    public function test_overture_backfill_fills_missing_canonical_category_but_never_changes_owned_pages(): void
    {
        $unclaimed = $this->page();
        $claimed = $this->page(['is_unclaimed' => false, 'claimed_at' => now()]);
        $before = $claimed->fresh()->getAttributes();
        foreach ([$unclaimed, $claimed] as $page) {
            $this->association($page, 'overture_places', 'owner-policy-'.$page->id, [
                'address' => ['locality' => 'Original village'],
                'source_categories' => [['key' => 'cafe', 'label' => 'Cafe', 'catalog_key' => 'food_catering.cafes']],
            ]);
        }
        $result = $this->command('overture_places', apply: true);
        $this->assertSame(1, $result['counts']['updated']);
        $this->assertSame(1, $result['counts']['protected']);
        $this->assertSame('Original village', $unclaimed->fresh()->setup['address']['city']);
        $this->assertSame('food_catering.cafes', $unclaimed->fresh()->category_key);
        $this->assertSame($before, $claimed->fresh()->getAttributes());
        $this->assertDatabaseCount('business_import_cities', 1);
    }

    public function test_backfill_resolves_only_explicit_city_aliases_for_both_providers_without_overwriting_existing_city(): void
    {
        foreach (['overture_places', 'foursquare_places'] as $provider) {
            $page = $this->page(['setup' => ['address' => ['city' => 'Tel Aviv']]]);
            $metadata = $this->metadata('תל אביב-יפו');
            $metadata['source_categories'][] = ['key' => 'cafe', 'label' => 'Cafe', 'catalog_key' => 'food_catering.cafes'];
            $this->association($page, $provider, $this->id(40), $metadata);
            $result = $this->command($provider, apply: true);
            $this->assertSame(1, $result['counts']['updated']);
            $this->assertSame(0, $result['counts']['conflict']);
            $this->assertSame('Tel Aviv', $page->fresh()->setup['address']['city']);
            $this->assertSame('food_catering.cafes', $page->fresh()->category_key);
        }
        $this->assertDatabaseCount('business_import_cities', 0);
    }

    public function test_pages_without_missing_fields_or_unknown_categories_do_not_receive_empty_arrays_or_timestamp_changes(): void
    {
        $page = $this->page(['setup' => ['address' => ['city' => 'Tel Aviv']]]);
        $this->association($page, 'overture_places', 'empty-categories', ['source_city' => 'תל אביב', 'source_categories' => [], 'taxonomy' => ['primary' => 'ignored_legacy_type']]);
        $before = $page->fresh()->getAttributes();
        $this->travel(5)->minutes();
        $result = $this->command('overture_places', apply: true);
        $this->assertSame(1, $result['counts']['unchanged']);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertDatabaseCount('business_import_categories', 0);
        $this->assertDatabaseCount('business_import_cities', 0);
    }

    public function test_multiple_source_label_order_stays_stable_on_repeated_backfills(): void
    {
        $page = $this->page(['setup' => ['address' => ['city' => 'Tel Aviv']]]);
        $this->association($page, 'overture_places', 'first-labels', ['source_city' => 'Tel Aviv', 'taxonomy' => ['primary' => 'first_activity']]);
        $this->association($page, 'foursquare_places', $this->id(41), $this->metadata('Tel Aviv'));
        $this->command('overture_places', apply: true);
        $after = $page->fresh()->getAttributes();
        $this->travel(5)->minutes();
        $this->assertSame(1, $this->command('foursquare_places', apply: true)['counts']['unchanged']);
        $this->assertSame(1, $this->command('overture_places', apply: true)['counts']['unchanged']);
        $this->assertSame($after, $page->fresh()->getAttributes());
    }

    public function test_review_backfill_is_bounded_resumable_and_never_creates_a_page_or_source_association(): void
    {
        $first = $this->review(10, 'First pending town');
        $second = $this->review(11, 'Second pending town');
        $resolved = $this->review(12, 'Resolved town');
        $resolved->update(['status' => 'resolved']);
        $before = $first->fresh()->getAttributes();
        $preview = $this->command('foursquare_places', scope: 'reviews', limit: 1);
        $this->assertSame($first->id, $preview['next_after']);
        $this->assertFalse($preview['eof']);
        $this->assertDatabaseCount('business_import_cities', 0);
        $part = $this->command('foursquare_places', scope: 'reviews', apply: true, limit: 1);
        $this->assertSame(1, $part['counts']['review']);
        $last = $this->command('foursquare_places', scope: 'reviews', apply: true, after: $part['next_after'], limit: 1);
        $this->assertSame($second->id, $last['next_after']);
        $this->assertTrue($last['eof']);
        $this->assertDatabaseCount('business_import_cities', 2);
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertNull(BusinessImportCity::first()->example_page_id);
    }

    public function test_backfill_never_completes_an_address_into_another_existing_business_identity(): void
    {
        $original = $this->page(['name' => 'Same branch name', 'setup' => ['address' => ['city' => 'Tel Aviv', 'street' => 'Shared Road', 'number' => '12']]]);
        $incomplete = $this->page(['name' => 'Same branch name']);
        $this->association($incomplete, 'overture_places', 'conflicting-location', [
            'address' => ['locality' => 'Tel Aviv', 'freeform' => 'Shared Road', 'number' => '12'],
            'taxonomy' => ['primary' => 'original_source_activity'],
        ]);
        $before = [$original->fresh()->getAttributes(), $incomplete->fresh()->getAttributes()];
        foreach ([false, true] as $apply) {
            $result = $this->command('overture_places', apply: $apply);
            $this->assertSame(1, $result['counts']['conflict']);
            $this->assertSame(0, $result['counts']['updated']);
            $this->assertSame($before[0], $original->fresh()->getAttributes());
            $this->assertSame($before[1], $incomplete->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('business_import_categories', 1);
    }

    public function test_backfill_only_reads_requested_provider_rows_in_bounded_batches(): void
    {
        $page = $this->page();
        $first = $this->association($page, 'foursquare_places', $this->id(20), $this->metadata('Bounded town'));
        $this->association($page, 'overture_places', 'other-provider', $this->metadata('Other town'));
        $this->association($page, 'foursquare_places', $this->id(21), $this->metadata('Later town'));
        DB::enableQueryLog();
        $result = $this->command('foursquare_places', limit: 1);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertSame(1, $result['counts']['processed']);
        $this->assertSame($first->id, $result['next_after']);
        $this->assertFalse($result['eof']);
        $this->assertTrue($queries->contains(fn (string $query): bool => str_contains($query, 'business_import_sources') && str_contains($query, 'limit 100')));
        $this->assertDatabaseCount('business_import_cities', 0);
    }

    public function test_private_aggregation_failure_rolls_back_backfilled_page_fields(): void
    {
        $page = $this->page();
        $this->association($page, 'foursquare_places', $this->id(30), $this->metadata('Rollback town'));
        $before = $page->fresh()->getAttributes();
        DB::unprepared("CREATE TRIGGER catalog_city_failure BEFORE INSERT ON business_import_cities BEGIN SELECT RAISE(ABORT, 'forced catalog failure'); END");
        try {
            $this->assertSame(1, Artisan::call('business-import:backfill-catalog', ['--provider' => 'foursquare_places', '--apply' => true]));
            $this->assertSame($before, $page->fresh()->getAttributes());
            $this->assertDatabaseCount('business_import_cities', 0);
            $this->assertDatabaseCount('business_import_categories', 0);
        } finally {
            DB::unprepared('DROP TRIGGER catalog_city_failure');
        }
    }

    private function command(string $provider, string $scope = 'sources', bool $apply = false, int $after = 0, int $limit = 9000): array
    {
        $status = Artisan::call('business-import:backfill-catalog', [
            '--provider' => $provider, '--scope' => $scope, '--after' => $after, '--limit' => $limit,
            ...($apply ? ['--apply' => true] : []),
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function page(array $attributes = []): Page
    {
        $worker = User::where('role', 'ai_worker')->firstOrFail();

        return Page::create(['user_id' => $worker->id, 'created_by_user_id' => $worker->id, 'type' => 'business', 'is_unclaimed' => true,
            'name' => 'Legacy business '.Str::uuid(), 'setup' => [], ...$attributes]);
    }

    private function association(Page $page, string $provider, string $id, array $metadata): BusinessImportSource
    {
        return BusinessImportSource::create(['page_id' => $page->id, 'provider' => $provider, 'source_id' => $id,
            'url' => $provider === 'foursquare_places' ? 'https://foursquare.com/placemakers/review-place/'.$id : 'https://explore.overturemaps.org/?gers='.$id,
            'metadata' => $metadata]);
    }

    private function metadata(string $city): array
    {
        return ['source_city' => $city, 'source_categories' => [['key' => 'unmapped_activity', 'label' => 'Original source activity', 'catalog_key' => null]]];
    }

    private function payload(int $number, string $city): array
    {
        $id = $this->id($number);

        return ['name' => 'New source name '.$number, 'address' => ['city' => $city], 'source' => [
            'provider' => 'foursquare_places', 'id' => $id, 'url' => 'https://foursquare.com/placemakers/review-place/'.$id, 'metadata' => $this->metadata($city),
        ]];
    }

    private function review(int $number, string $city): BusinessImportMatchReview
    {
        return BusinessImportMatchReview::create(['provider' => 'foursquare_places', 'source_id' => $this->id($number),
            'status' => 'pending', 'reason' => 'unconfirmed_identity', 'payload' => $this->payload($number, $city),
            'candidates' => [], 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    private function id(int $number): string
    {
        return str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    private function postBusiness(array $payload)
    {
        return $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
