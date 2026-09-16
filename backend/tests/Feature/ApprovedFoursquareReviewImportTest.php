<?php

namespace Tests\Feature;

use App\Models\BusinessImportClient;
use App\Models\BusinessImportClosure;
use App\Models\BusinessImportMatchReview;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use App\Services\BusinessImportService;
use App\Services\FoursquareImportMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ApprovedFoursquareReviewImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_preview_validates_existing_reviews_without_writing_any_database_rows(): void
    {
        $review = $this->review(1);
        $before = $review->fresh()->getAttributes();
        DB::enableQueryLog();
        $result = $this->command();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['counts']['would_create']);
        $this->assertSame(0, $result['counts']['created']);
        $this->assertFalse($queries->contains(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1));
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
        $this->assertDatabaseCount('business_import_cities', 0);
        $this->assertDatabaseCount('business_import_categories', 0);
        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_explicit_apply_creates_a_separate_business_and_preserves_claimed_alias_page_and_original_source(): void
    {
        $claimed = $this->page(['user_id' => User::factory()->create()->id, 'is_unclaimed' => false, 'name' => 'Existing owned shop', 'phone' => '03-1234567', 'setup' => ['address' => ['city' => 'Haifa']]]);
        $overture = $this->association($claimed, 'overture_places', 'original-overture', ['sources' => [
            ['dataset' => 'Foursquare', 'property' => '', 'record_id' => $this->id(2)],
        ]]);
        app(FoursquareImportMatchingService::class)->syncAliases($overture);
        $review = $this->review(2, 'source_alias_location_conflict');
        $payload = $review->payload;
        $payload['name'] = $claimed->name;
        $payload['phone'] = $claimed->phone;
        $review->update(['payload' => $payload]);
        $before = [$claimed->fresh()->getAttributes(), $overture->fresh()->getAttributes(), $review->fresh()->payload];
        $first = $this->command(apply: true);
        $this->assertSame(1, $first['counts']['created']);
        $source = BusinessImportSource::where('provider', 'foursquare_places')->sole();
        $this->assertNotSame($claimed->id, $source->page_id);
        $new = Page::findOrFail($source->page_id);
        $this->assertTrue($new->is_unclaimed);
        $this->assertSame($claimed->name, $new->name);
        $this->assertSame('Unlisted source town', $new->setup['address']['city']);
        $this->assertSame('Original source activity', $new->setup['imported_categories'][0]['label']);
        $this->assertSame('foursquare_places', $new->setup['imported_categories'][0]['provider']);
        $this->assertSame($before[0], $claimed->fresh()->getAttributes());
        $this->assertSame($before[1], $overture->fresh()->getAttributes());
        $this->assertSame($before[2], $review->fresh()->payload);
        $this->assertSame($payload['source']['metadata'], $source->metadata);
        $this->assertDatabaseCount('business_import_source_aliases', 1);
        $this->assertDatabaseCount('business_import_cities', 1);
        $this->assertDatabaseCount('business_import_categories', 1);
        $this->assertSame('imported_separately', $review->fresh()->status);
        $receipt = $review->fresh()->resolution;
        $this->assertSame($source->page_id, $receipt['page_id']);
        $this->assertSame(app(BusinessImportService::class)->payloadHash($payload['source']['metadata']), $receipt['source_metadata_hash']);
        $retry = $this->command(apply: true);
        $this->assertSame(0, $retry['counts']['created']);
        $this->assertSame(1, $retry['counts']['already_associated']);
        $this->assertSame($receipt, $review->fresh()->resolution);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_explicit_review_approval_can_create_an_extra_entry_even_for_an_exact_name_and_address_overlap(): void
    {
        $payload = $this->payload(3);
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Same Road', 'number' => '5'];
        $existing = $this->page(['name' => $payload['name'], 'setup' => ['address' => $payload['address']]]);
        $before = $existing->fresh()->getAttributes();
        $this->review(3, 'ambiguous_confirmed_location')->update(['payload' => $payload]);
        $this->assertSame(1, $this->command(apply: true)['counts']['created']);
        $this->assertDatabaseCount('pages', 2);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertCount(2, Page::all()->pluck('public_path')->unique());
    }

    public function test_existing_direct_foursquare_associations_are_never_duplicated_or_moved_even_when_claimed_or_deleted(): void
    {
        foreach ([4, 5] as $number) {
            $page = $this->page(['is_unclaimed' => false]);
            $source = $this->association($page, 'foursquare_places', $this->id($number), ['preserved' => true]);
            if ($number === 5) {
                $page->delete();
            }
            $before = $source->fresh()->getAttributes();
            $review = $this->review($number);
            $this->assertSame('already_associated', app(BusinessImportService::class)->importApprovedFoursquareReview($review->id, false)['status']);
            $this->assertSame($before, $source->fresh()->getAttributes());
            $this->assertSame('linked_existing', $review->fresh()->status);
            $this->assertNull($review->fresh()->resolution);
        }
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_import_sources', 2);
    }

    public function test_closed_tombstoned_and_invalid_sources_remain_unimported(): void
    {
        $closed = $this->review(6);
        $payload = $closed->payload;
        $payload['source']['metadata']['date_closed'] = '2026-09-01';
        $closed->update(['payload' => $payload]);
        $originalClosed = $this->review(7);
        $payload = $originalClosed->payload;
        $payload['source']['metadata']['original_record'] = ['date_closed' => '2026-09-01'];
        $originalClosed->update(['payload' => $payload]);
        $this->review(8);
        $closure = BusinessImportClosure::create(['provider' => 'foursquare_places', 'source_id' => $this->id(8), 'date_closed' => '2026-09-01', 'status' => 'removed', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        DB::table('business_import_source_tombstones')->insert(['provider' => 'foursquare_places', 'source_id' => $this->id(8), 'closure_id' => $closure->id, 'created_at' => now()]);
        $invalid = $this->review(9);
        $payload = $invalid->payload;
        $payload['source']['metadata']['country'] = 'US';
        $invalid->update(['payload' => $payload]);
        $mismatch = $this->review(10);
        $payload = $mismatch->payload;
        $payload['source']['id'] = $this->id(999);
        $mismatch->update(['payload' => $payload]);
        $result = $this->command(apply: true);
        $this->assertSame(3, $result['counts']['closed']);
        $this->assertSame(2, $result['counts']['invalid']);
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
        $this->assertSame(5, BusinessImportMatchReview::where('status', 'pending')->count());
    }

    public function test_cursor_and_high_water_mark_limit_the_approved_cohort_and_database_reads(): void
    {
        $first = $this->review(11);
        $last = $this->review(12);
        $later = $this->review(13);
        DB::enableQueryLog();
        $part = $this->command(apply: true, limit: 1, through: $last->id);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertSame($first->id, $part['next_after']);
        $this->assertFalse($part['eof']);
        $this->assertSame($last->id, $part['through']);
        $this->assertTrue($queries->contains(fn ($sql) => str_contains($sql, 'business_import_match_reviews') && str_contains($sql, 'limit 100')));
        $second = $this->command(apply: true, after: $part['next_after'], limit: 1, through: $part['through']);
        $this->assertTrue($second['eof']);
        $this->assertSame($last->id, $second['next_after']);
        $this->assertSame('pending', $later->fresh()->status);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_failed_record_rolls_back_page_source_catalog_and_receipt_and_reports_last_committed_cursor(): void
    {
        $first = $this->review(14);
        $failing = $this->review(15);
        $payload = $failing->payload;
        $payload['source']['metadata']['source_categories'][0]['key'] = 'failing_category';
        $failing->update(['payload' => $payload]);
        DB::unprepared("CREATE TRIGGER approved_review_failure BEFORE INSERT ON business_import_categories WHEN NEW.raw_value = 'failing_category' BEGIN SELECT RAISE(ABORT, 'forced review failure'); END");
        try {
            $status = Artisan::call('business-import:import-foursquare-reviews', ['--apply' => true]);
            $output = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $status);
            $this->assertSame($first->id, $output['next_after']);
            $this->assertSame(1, $output['counts']['created']);
            $this->assertFalse($output['eof']);
            $this->assertDatabaseCount('pages', 1);
            $this->assertDatabaseCount('page_identity_keys', 1);
            $this->assertDatabaseCount('business_import_sources', 1);
            $this->assertDatabaseCount('business_import_pages', 1);
            $this->assertSame('pending', $failing->fresh()->status);
            $this->assertNull($failing->fresh()->resolution);
        } finally {
            DB::unprepared('DROP TRIGGER approved_review_failure');
        }
        $this->assertSame(1, $this->command(apply: true, after: $first->id)['counts']['created']);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_manifests_export_only_durable_verified_creation_receipts_and_replay_without_creating_pages(): void
    {
        $review = $this->review(16);
        $first = $this->manifestPath();
        $second = $this->manifestPath();
        $changed = $this->manifestPath();
        try {
            $result = $this->command(apply: true, decisions: $first);
            $this->assertSame(1, $result['decisions_exported']);
            $manifest = json_decode(file_get_contents($first), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $manifest['version']);
            $this->assertSame('foursquare_places', $manifest['provider']);
            $this->assertSame([$review->fresh()->resolution], $manifest['decisions']);
            $this->command(apply: true, decisions: $second);
            $this->assertSame(file_get_contents($first), file_get_contents($second));
            $source = BusinessImportSource::sole();
            $receipt = $review->fresh()->resolution;
            $source->update(['metadata' => [...$source->metadata, 'refresh_marker' => true]]);
            $this->assertSame($receipt, $review->fresh()->resolution);
            $this->assertSame(0, $this->command(decisions: $changed)['decisions_exported']);
            $this->assertSame([], json_decode(file_get_contents($changed), true)['decisions']);
            $this->assertDatabaseCount('pages', 1);
        } finally {
            foreach ([$first, $second, $changed] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_manifest_existing_file_and_invalid_limit_fail_before_any_import(): void
    {
        $this->review(17);
        $path = $this->manifestPath();
        file_put_contents($path, 'existing manifest');
        try {
            $this->assertSame(1, Artisan::call('business-import:import-foursquare-reviews', ['--apply' => true, '--decisions' => $path]));
            $this->assertSame('existing manifest', file_get_contents($path));
            $this->assertSame(1, Artisan::call('business-import:import-foursquare-reviews', ['--apply' => true, '--limit' => 9001]));
            $this->assertDatabaseCount('pages', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_normal_api_imports_still_require_review_and_cannot_enable_standalone_creation(): void
    {
        $client = Client::factory()->asClientCredentials()->create();
        $scopes = [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE];
        BusinessImportClient::create(['oauth_client_id' => $client->getKey(), 'name' => 'Approval isolation test', 'allowed_scopes' => $scopes, 'active' => true]);
        Passport::actingAsClient($client, $scopes);
        $this->page(['phone' => '03-3333333']);
        $payload = $this->payload(18);
        $payload['phone'] = '03-3333333';
        $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409)->assertJsonValidationErrors('review_required');
        $this->postJson('/api/v1/business-import/businesses', [...$payload, 'approve_review' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_import_sources', 0);
        $this->assertNull(BusinessImportMatchReview::sole()->resolution);
    }

    private function command(bool $apply = false, int $after = 0, int $limit = 9000, ?int $through = null, ?string $decisions = null): array
    {
        $status = Artisan::call('business-import:import-foursquare-reviews', [
            '--after' => $after, '--limit' => $limit, ...($apply ? ['--apply' => true] : []),
            ...($through === null ? [] : ['--through' => $through]), ...($decisions === null ? [] : ['--decisions' => $decisions]),
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function review(int $number, string $reason = 'unconfirmed_identity'): BusinessImportMatchReview
    {
        return BusinessImportMatchReview::create(['provider' => 'foursquare_places', 'source_id' => $this->id($number), 'reason' => $reason,
            'status' => 'pending', 'payload' => $this->payload($number), 'candidates' => [], 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    private function payload(int $number): array
    {
        $id = $this->id($number);

        return ['name' => 'Foursquare source shop '.$number, 'public_description' => 'Original source text',
            'address' => ['city' => 'Unlisted source town'], 'source' => [
                'provider' => 'foursquare_places', 'id' => $id, 'url' => 'https://foursquare.com/placemakers/review-place/'.$id,
                'metadata' => ['source_id' => $id, 'country' => 'IL', 'date_closed' => null, 'source_city' => 'Unlisted source town',
                    'source_categories' => [['key' => 'unmapped_activity', 'label' => 'Original source activity', 'catalog_key' => null]]],
            ]];
    }

    private function page(array $attributes = []): Page
    {
        $worker = User::where('role', 'ai_worker')->firstOrFail();

        return Page::create(['user_id' => $worker->id, 'created_by_user_id' => $worker->id, 'type' => 'business', 'is_unclaimed' => true,
            'name' => 'Original business '.Str::uuid(), 'setup' => [], ...$attributes]);
    }

    private function association(Page $page, string $provider, string $id, array $metadata): BusinessImportSource
    {
        return BusinessImportSource::create(['page_id' => $page->id, 'provider' => $provider, 'source_id' => $id,
            'url' => $provider === 'foursquare_places' ? 'https://foursquare.com/placemakers/review-place/'.$id : 'https://explore.overturemaps.org/?gers='.$id,
            'metadata' => $metadata]);
    }

    private function id(int $number): string
    {
        return str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    private function manifestPath(): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.'/foursquare-review-decisions-'.Str::uuid().'.json';
    }
}
