<?php

namespace Tests\Feature;

use App\Exceptions\BusinessImportException;
use App\Models\BusinessImportClient;
use App\Models\BusinessImportClosure;
use App\Models\BusinessImportClosureEvent;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use App\Services\ClosedBusinessService;
use App\Services\FoursquareImportMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ClosedBusinessApiTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = User::factory()->create(['role' => 'ai_worker']);
        $this->authorize();
        Storage::fake('public');
    }

    public function test_preview_is_the_default_and_does_not_change_pages_sources_or_closure_state(): void
    {
        $page = $this->page();
        $this->overture($page, 'preview', 1);
        $before = $page->fresh()->getAttributes();
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([1]))->assertOk()
            ->assertJsonPath('data.dry_run', true)->assertJsonPath('data.counts.would_remove', 1)
            ->assertJsonPath('data.items.0.page_id', $page->id);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertDatabaseCount('business_import_closures', 0);
        $this->assertDatabaseCount('business_import_closure_events', 0);
        $this->assertDatabaseCount('business_import_source_tombstones', 0);
        $this->assertDatabaseCount('business_import_source_aliases', 1);
    }

    public function test_exact_overture_alias_removes_only_the_closed_branch_and_retains_all_source_tombstones(): void
    {
        $closed = $this->page(['name' => 'Shared chain', 'phone' => '03-1234567', 'setup' => ['address' => ['city' => 'Haifa', 'street' => 'Road', 'number' => '1']]]);
        $open = $this->page(['name' => 'Shared chain', 'phone' => '03-1234567', 'setup' => ['address' => ['city' => 'Haifa', 'street' => 'Road', 'number' => '2']]]);
        $this->overture($closed, 'closed-branch', 2);
        $this->overture($open, 'open-branch', 3);
        $this->source($closed, 'osm_places', 'node:500');
        $this->source($closed, 'data_gov_ckan', 'registry:500');
        $response = $this->postJson('/api/v1/business-import/closed-businesses', $this->request([2], apply: true))->assertOk()
            ->assertJsonPath('data.counts.removed', 1)->assertJsonPath('data.items.0.page_id', $closed->id);
        $this->assertNull($closed->fresh());
        $this->assertNotNull($open->fresh());
        $this->assertDatabaseMissing('page_identity_keys', ['page_id' => $closed->id]);
        $this->assertDatabaseHas('business_import_sources', ['provider' => 'overture_places', 'source_id' => 'closed-branch', 'page_id' => null]);
        $closure = BusinessImportClosure::sole();
        $this->assertSame($closed->id, $closure->removed_page_id);
        $this->assertSame('Shared chain', $closure->page_snapshot['page']['name']);
        $this->assertCount(3, $closure->page_snapshot['sources']);
        $this->assertSame($response->json('data.items.0.page_id'), BusinessImportClosureEvent::sole()->result['page_id']);
        foreach ([['overture_places', 'closed-branch'], ['osm_places', 'node:500'], ['data_gov_ckan', 'registry:500'], ['foursquare_places', $this->id(2)]] as [$provider, $id]) {
            $this->assertDatabaseHas('business_import_source_tombstones', ['provider' => $provider, 'source_id' => $id, 'closure_id' => $closure->id]);
            $this->assertClosed(['provider' => $provider, 'id' => $id, 'metadata' => []]);
        }
        app(ClosedBusinessService::class)->assertSourceOpen(['provider' => 'overture_places', 'id' => 'open-branch', 'metadata' => []]);
    }

    public function test_repeated_apply_is_idempotent_and_each_new_snapshot_keeps_a_separate_audit_event(): void
    {
        $page = $this->page();
        $this->source($page, 'foursquare_places', $this->id(4));
        $request = $this->request([4], apply: true);
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertOk()->assertJsonPath('data.counts.removed', 1);
        $archive = BusinessImportClosure::sole()->page_snapshot;
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertOk()
            ->assertJsonPath('data.counts.already_removed', 1)->assertJsonPath('data.items.0.replayed', true);
        $this->assertDatabaseCount('business_import_closure_events', 1);
        $request['snapshot_id'] = '100002';
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertOk()->assertJsonPath('data.counts.already_removed', 1);
        $this->assertDatabaseCount('business_import_closures', 1);
        $this->assertDatabaseCount('business_import_closure_events', 2);
        $this->assertSame($archive, BusinessImportClosure::sole()->page_snapshot);
        $request['businesses'][0]['date_closed'] = '2026-08-01';
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertStatus(409)->assertJsonValidationErrors('closure_snapshot_conflict');
        $this->assertDatabaseCount('business_import_closure_events', 2);
    }

    public function test_failed_audit_write_rolls_back_page_removal_source_links_and_tombstones(): void
    {
        $page = $this->page(['logo_path' => 'closure-fixture.png']);
        Storage::disk('public')->put('closure-fixture.png', 'fixture');
        $this->source($page, 'foursquare_places', $this->id(5));
        DB::unprepared("CREATE TRIGGER closure_audit_failure BEFORE INSERT ON business_import_closure_events BEGIN SELECT RAISE(ABORT, 'forced closure audit failure'); END");
        try {
            $this->postJson('/api/v1/business-import/closed-businesses', $this->request([5], apply: true))->assertStatus(500);
            $this->assertNotNull($page->fresh());
            $this->assertDatabaseHas('business_import_sources', ['provider' => 'foursquare_places', 'source_id' => $this->id(5), 'page_id' => $page->id]);
            $this->assertDatabaseCount('business_import_closures', 0);
            $this->assertDatabaseCount('business_import_closure_events', 0);
            $this->assertDatabaseCount('business_import_source_tombstones', 0);
            Storage::disk('public')->assertExists('closure-fixture.png');
        } finally {
            DB::unprepared('DROP TRIGGER closure_audit_failure');
        }
    }

    public function test_claimed_transferred_and_inconsistently_owned_pages_are_protected_and_recorded_for_review(): void
    {
        $owner = User::factory()->create();
        $pages = [
            $this->page(['is_unclaimed' => false]),
            $this->page(['claimed_at' => now()]),
            $this->page(['user_id' => $owner->id]),
            $this->page(['user_id' => $owner->id, 'created_by_user_id' => null]),
        ];
        foreach ($pages as $index => $page) {
            $this->source($page, 'foursquare_places', $this->id(10 + $index));
        }
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([10, 11, 12, 13], apply: true))->assertOk()
            ->assertJsonPath('data.counts.protected_claimed', 4)->assertJsonPath('data.counts.removed', 0);
        $this->assertDatabaseCount('pages', 4);
        $this->assertSame(4, BusinessImportClosure::where('status', 'protected_claimed')->count());
        $this->assertSame(0, BusinessImportClosure::whereNotNull('removed_at')->count());
    }

    public function test_direct_mapping_and_all_aliases_must_agree_and_address_conflicts_never_remove_a_page(): void
    {
        $direct = $this->page();
        $alias = $this->page();
        $this->source($direct, 'foursquare_places', $this->id(20));
        $this->overture($alias, 'other-identity', 20);
        $moved = $this->page(['setup' => ['address' => ['city' => 'Haifa', 'street' => 'Original Road', 'number' => '1']]]);
        $this->overture($moved, 'moved-identity', 21);
        $request = $this->request([20, 21], apply: true);
        $request['businesses'][1]['address'] = ['city' => 'Haifa', 'street' => 'Other Road', 'number' => '2'];
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertOk()
            ->assertJsonPath('data.counts.review_required', 2)
            ->assertJsonPath('data.items.0.reason', 'ambiguous_source_identity')
            ->assertJsonPath('data.items.0.candidate_page_ids', [$direct->id, $alias->id])
            ->assertJsonPath('data.items.1.reason', 'source_address_conflict');
        $this->assertDatabaseCount('pages', 3);
    }

    public function test_unmatched_closed_id_is_not_a_name_match_and_blocks_later_direct_or_new_overture_imports(): void
    {
        $page = $this->page(['name' => 'Unrelated existing business']);
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([30], apply: true))->assertOk()
            ->assertJsonPath('data.counts.unmatched', 1)->assertJsonPath('data.counts.removed', 0);
        $this->assertNotNull($page->fresh());
        $source = [
            'provider' => 'foursquare_places', 'id' => $this->id(30),
            'url' => 'https://foursquare.com/placemakers/review-place/'.$this->id(30), 'metadata' => [],
        ];
        $this->assertClosed($source);
        $alias = ['provider' => 'overture_places', 'id' => 'new-release-id', 'url' => 'https://explore.overturemaps.org/?gers=new-release-id', 'metadata' => $this->aliases(30)];
        $this->assertClosed($alias);
        foreach ([$source, $alias] as $descriptor) {
            $payload = ['name' => 'Cannot be recreated', 'source' => $descriptor];
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)->assertStatus(409)->assertJsonValidationErrors('source_closed');
            $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => (string) Str::uuid()])
                ->assertStatus(409)->assertJsonValidationErrors('source_closed');
        }
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_only_record_level_overture_aliases_are_used_for_source_suppression(): void
    {
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([31], apply: true))->assertOk();
        $metadata = $this->aliases(31);
        $metadata['sources'][0]['property'] = '/properties/phone';
        app(ClosedBusinessService::class)->assertSourceOpen(['provider' => 'overture_places', 'id' => 'property-only', 'metadata' => $metadata]);
        $this->assertTrue(true);
    }

    public function test_invalid_dates_country_ids_duplicates_and_oversized_batches_never_write_closure_state(): void
    {
        foreach ([
            ['date_closed', null], ['date_closed', ''], ['date_closed', '2026-02-30'], ['date_closed', '2026-09-02'],
            ['date_closed', '0000-00-00'], ['country', 'DE'], ['source_id', 'not-an-id'],
        ] as [$field, $value]) {
            $request = $this->request([40], apply: true);
            $request['businesses'][0][$field] = $value;
            $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertUnprocessable()->assertJsonValidationErrors('businesses.0.'.$field);
        }
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([40, 40], apply: true))->assertUnprocessable();
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request(range(100, 200), apply: true))->assertUnprocessable()->assertJsonValidationErrors('businesses');
        $this->assertDatabaseCount('business_import_closures', 0);
        $this->assertDatabaseCount('business_import_source_tombstones', 0);
    }

    public function test_only_registered_write_scope_clients_can_preview_or_apply_closures(): void
    {
        $this->authorize([BusinessImportClient::SCOPE_READ]);
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([41]))->assertForbidden();
        Passport::actingAsClient(Client::factory()->asClientCredentials()->create(), [BusinessImportClient::SCOPE_WRITE]);
        $this->postJson('/api/v1/business-import/closed-businesses', $this->request([41], apply: true))->assertForbidden();
        $this->assertDatabaseCount('business_import_closures', 0);
    }

    public function test_long_snapshot_street_evidence_is_kept_without_rejecting_or_truncating_the_batch(): void
    {
        $request = $this->request([44], apply: true);
        $request['businesses'][0]['address'] = ['city' => 'Haifa', 'street' => str_repeat('A', 300)];
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertOk()->assertJsonPath('data.counts.unmatched', 1);
        $this->assertSame(str_repeat('A', 300), BusinessImportClosureEvent::sole()->evidence['address']['street']);
        $request['snapshot_id'] = '100003';
        $request['businesses'][0]['address']['street'] .= 'B';
        $this->postJson('/api/v1/business-import/closed-businesses', $request)->assertUnprocessable()->assertJsonValidationErrors('businesses.0.address.street');
        $this->assertDatabaseCount('business_import_closure_events', 1);
    }

    public function test_lookups_are_bounded_to_exact_source_and_page_ids_instead_of_loading_the_business_catalog(): void
    {
        $page = $this->page();
        $this->source($page, 'foursquare_places', $this->id(42));
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ClosedBusinessService::class)->process('test', $this->request([42]));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $pageQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from "pages"'));
        $this->assertNotEmpty($pageQueries);
        foreach ($pageQueries as $query) {
            $this->assertStringContainsString('"pages"."id" = ?', $query);
            $this->assertStringContainsString('limit 1', $query);
        }
        $this->assertTrue($queries->contains(fn (string $query): bool => str_contains($query, 'business_import_source_aliases') && str_contains($query, 'limit 101')));
    }

    public function test_artisan_command_defaults_to_preview_and_supports_a_bounded_line_cursor(): void
    {
        $page = $this->page();
        $this->source($page, 'foursquare_places', $this->id(52));
        $path = storage_path('framework/testing/closed-businesses-'.Str::uuid().'.jsonl');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, implode("\n", array_map(fn (int $number): string => json_encode($this->request([$number])['businesses'][0], JSON_THROW_ON_ERROR), [51, 52, 53]))."\n");
        try {
            $options = ['file' => $path, '--snapshot' => '100001', '--release' => '2026-09-01', '--after' => 1, '--limit' => 1];
            $this->artisan('business-import:remove-closed', $options)->expectsOutputToContain('"would_remove":1')->assertSuccessful();
            $this->assertNotNull($page->fresh());
            $this->assertDatabaseCount('business_import_closures', 0);
            $this->artisan('business-import:remove-closed', [...$options, '--apply' => true])->expectsOutputToContain('"next_after":2')->assertSuccessful();
            $this->assertNull($page->fresh());
            $this->assertDatabaseCount('business_import_closures', 1);
        } finally {
            unlink($path);
        }
    }

    private function authorize(array $scopes = [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]): void
    {
        $client = Client::factory()->asClientCredentials()->create();
        BusinessImportClient::create(['oauth_client_id' => $client->getKey(), 'name' => 'Closure test', 'allowed_scopes' => $scopes, 'active' => true]);
        Passport::actingAsClient($client, $scopes);
    }

    private function page(array $attributes = []): Page
    {
        return Page::create([
            'user_id' => $this->worker->id, 'created_by_user_id' => $this->worker->id,
            'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => true, 'name' => 'Imported business '.Str::uuid(),
            'setup' => [], ...$attributes,
        ]);
    }

    private function source(Page $page, string $provider, string $id, array $metadata = []): BusinessImportSource
    {
        return BusinessImportSource::create(['provider' => $provider, 'source_id' => $id, 'page_id' => $page->id, 'url' => 'https://example.com/record', 'metadata' => $metadata]);
    }

    private function overture(Page $page, string $id, int $alias): void
    {
        app(FoursquareImportMatchingService::class)->syncAliases($this->source($page, 'overture_places', $id, $this->aliases($alias)));
    }

    private function aliases(int $number): array
    {
        return ['sources' => [['dataset' => 'Foursquare', 'provider' => 'foursquare', 'property' => '', 'record_id' => $this->id($number)]]];
    }

    private function id(int $number): string
    {
        return str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    private function request(array $numbers, bool $apply = false): array
    {
        return ['snapshot_id' => '100001', 'release' => '2026-09-01', ...($apply ? ['dry_run' => false] : []),
            'businesses' => array_map(fn (int $number): array => ['source_id' => $this->id($number), 'date_closed' => '2026-08-15', 'country' => 'IL'], $numbers)];
    }

    private function assertClosed(array $source): void
    {
        try {
            app(ClosedBusinessService::class)->assertSourceOpen($source);
            $this->fail('A closed source was accepted for import.');
        } catch (BusinessImportException $error) {
            $this->assertSame('source_closed', $error->reason);
            $this->assertSame(409, $error->status);
        }
    }
}
