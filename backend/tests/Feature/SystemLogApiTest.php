<?php

namespace Tests\Feature;

use App\Models\BusinessImportClient;
use App\Models\SystemLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_import_client_records_run_once_and_rejects_changed_replay(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();

        $this->postJson('/api/v1/business-import/worker-runs', $report)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.run_id', $report['run_id'])
            ->assertJsonPath('data.replayed', false);

        $this->postJson('/api/v1/business-import/worker-runs', $report)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.replayed', true);

        $changed = $report;
        $changed['imported'] = 11;
        $this->postJson('/api/v1/business-import/worker-runs', $changed)
            ->assertStatus(409)
            ->assertJsonValidationErrors('run_id');

        $this->assertDatabaseCount('system_log_entries', 1);
        $this->assertDatabaseHas('system_log_entries', [
            'source' => SystemLogEntry::SOURCE_AUTOMATION_WORKER,
            'type' => SystemLogEntry::TYPE_BUSINESS_IMPORT_RUN,
            'external_id' => $report['run_id'],
            'status' => SystemLogEntry::STATUS_WARNING,
            'actor_identifier' => $client->getKey(),
        ]);
        $storedReport = SystemLogEntry::query()->sole()->data;
        $this->assertArrayNotHasKey('source_requests', $storedReport);
        $this->assertArrayNotHasKey('source_errors', $storedReport);
        $this->assertArrayNotHasKey('deferred_target_combinations', $storedReport);
        $this->assertArrayNotHasKey('overture_progress', $storedReport);
        $this->assertArrayNotHasKey('foursquare_progress', $storedReport);
        $this->assertArrayNotHasKey('review', $storedReport);
    }

    public function test_foursquare_review_and_progress_survive_admin_reads_and_are_idempotent(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['used_sources'] = ['foursquare_places'];
        $report['source_counts'] = ['foursquare_places' => 24];
        $report['failed'] = 0;
        $report['incomplete'] = 0;
        $report['errors'] = [];
        $report['review'] = 2;
        $report['foursquare_progress'] = [
            'release' => '2026-09-09', 'total' => 100, 'scanned' => 24, 'remaining' => 76,
            'closed' => 1, 'pending' => 0, 'failed' => 0, 'review' => 2,
        ];
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertCreated();
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(SystemLogEntry::STATUS_WARNING, SystemLogEntry::sole()->status);
        $this->assertSame(0, SystemLogEntry::sole()->data['failed']);
        foreach (['review', 'foursquare_progress.review', 'foursquare_progress.closed'] as $path) {
            $changed = $report;
            data_set($changed, $path, data_get($changed, $path) + 1);
            $this->postJson('/api/v1/business-import/worker-runs', $changed)->assertStatus(409);
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/logs')->assertOk()
            ->assertJsonPath('data.items.0.data.review', 2)
            ->assertJsonPath('data.items.0.data.foursquare_progress', $report['foursquare_progress'])
            ->assertJsonPath('data.items.0.data.used_sources', ['foursquare_places']);
    }

    public function test_foursquare_progress_and_review_reject_invalid_counters(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['review'] = -1;
        $report['foursquare_progress'] = [
            'release' => '2026-09-09', 'total' => 100, 'scanned' => 5, 'remaining' => 101,
            'closed' => 6, 'invalid' => 6, 'pending' => -1, 'failed' => -1, 'review' => -1,
        ];
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertUnprocessable()
            ->assertJsonValidationErrors([
                'review', 'foursquare_progress.remaining', 'foursquare_progress.closed', 'foursquare_progress.invalid',
                'foursquare_progress.pending', 'foursquare_progress.failed', 'foursquare_progress.review',
            ]);
        $this->assertDatabaseCount('system_log_entries', 0);
    }

    public function test_optional_foursquare_invalid_count_is_stored_and_cannot_change_on_replay(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['used_sources'] = ['foursquare_places'];
        $report['source_counts'] = ['foursquare_places' => 24];
        $report['foursquare_progress'] = [
            'release' => '2026-09-09', 'total' => 100, 'scanned' => 24, 'remaining' => 76,
            'closed' => 1, 'invalid' => 2, 'pending' => 0, 'failed' => 0, 'review' => 0,
        ];

        $invalid = $report;
        $invalid['foursquare_progress']['invalid'] = -1;
        $this->postJson('/api/v1/business-import/worker-runs', $invalid)->assertUnprocessable()
            ->assertJsonValidationErrors('foursquare_progress.invalid');
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertCreated();
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertOk()
            ->assertJsonPath('data.replayed', true);
        $this->assertSame(2, SystemLogEntry::sole()->data['foursquare_progress']['invalid']);

        $changed = $report;
        $changed['foursquare_progress']['invalid'] = 3;
        $this->postJson('/api/v1/business-import/worker-runs', $changed)->assertStatus(409);
        $this->assertDatabaseCount('system_log_entries', 1);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/logs')->assertOk()
            ->assertJsonPath('data.items.0.data.foursquare_progress.invalid', 2);
    }

    public function test_rotation_counters_survive_storage_and_admin_reads_and_cannot_change_on_replay(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['scanned_target_combinations'] = 2;
        $report['productive_target_combinations'] = 2;
        $report['empty_target_combinations'] = 0;
        $report['unproductive_target_combinations'] = 0;
        $report['source_requests'] = 100;
        $report['source_errors'] = 1;
        $report['deferred_target_combinations'] = 1;
        $report['targets'][0]['successful'] = 10;
        $report['targets'][0]['planned'] = 0;
        $report['targets'][1]['successful'] = 2;
        $report['targets'][1]['planned'] = 0;
        $report['targets'][0]['deferred'] = false;
        $report['targets'][1]['deferred'] = true;

        $this->postJson('/api/v1/business-import/worker-runs', $report)
            ->assertCreated();
        $this->postJson('/api/v1/business-import/worker-runs', $report)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $counterPaths = [
            'scanned_target_combinations',
            'productive_target_combinations',
            'empty_target_combinations',
            'unproductive_target_combinations',
            'source_requests',
            'source_errors',
            'deferred_target_combinations',
            'targets.0.successful',
            'targets.0.planned',
            'targets.0.deferred',
            'targets.1.deferred',
        ];
        foreach ($counterPaths as $path) {
            $changed = $report;
            $value = data_get($report, $path);
            data_set($changed, $path, is_bool($value) ? ! $value : $value + 1);
            $this->postJson('/api/v1/business-import/worker-runs', $changed)
                ->assertStatus(409)
                ->assertJsonValidationErrors('run_id');
        }

        $this->assertDatabaseCount('system_log_entries', 1);
        $storedReport = SystemLogEntry::query()->sole()->data;
        foreach ($counterPaths as $path) {
            $this->assertSame(data_get($report, $path), data_get($storedReport, $path));
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->getJson('/api/v1/admin/logs?source=automation_worker')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.data.run_id', $report['run_id']);
        foreach ($counterPaths as $path) {
            $response->assertJsonPath('data.items.0.data.'.$path, data_get($report, $path));
        }
    }

    public function test_rotation_counters_reject_out_of_range_values(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['scanned_target_combinations'] = 1001;
        $report['productive_target_combinations'] = -1;
        $report['empty_target_combinations'] = 1001;
        $report['unproductive_target_combinations'] = -1;
        $report['source_requests'] = -1;
        $report['source_errors'] = -1;
        $report['deferred_target_combinations'] = 1001;
        $report['targets'][0]['successful'] = -1;
        $report['targets'][0]['planned'] = -1;
        $report['targets'][0]['deferred'] = 'invalid';

        $this->postJson('/api/v1/business-import/worker-runs', $report)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'scanned_target_combinations',
                'productive_target_combinations',
                'empty_target_combinations',
                'unproductive_target_combinations',
                'source_requests',
                'source_errors',
                'deferred_target_combinations',
                'targets.0.successful',
                'targets.0.planned',
                'targets.0.deferred',
            ]);

        $this->assertDatabaseCount('system_log_entries', 0);
    }

    public function test_source_errors_make_a_completed_run_a_warning_even_without_item_errors(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['failed'] = 0;
        $report['incomplete'] = 0;
        $report['errors'] = [];
        $report['source_requests'] = 1;
        $report['source_errors'] = 1;

        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertCreated();

        $this->assertDatabaseHas('system_log_entries', [
            'external_id' => $report['run_id'],
            'status' => SystemLogEntry::STATUS_WARNING,
        ]);
    }

    public function test_government_and_tel_aviv_jobs_keep_independent_run_logs_and_source_data(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_WRITE]);
        $government = $this->runReport();
        $government['source_requests'] = 1;
        $government['source_errors'] = 1;
        $government['errors'] = [
            ['stage' => 'source', 'message' => 'Data.gov.il request failed with HTTP 404.', 'context' => ['source' => 'data_gov_ckan']],
        ];

        // A separate Tel Aviv run can import pending records without requesting its source.
        $telAviv = $this->runReport();
        $telAviv['used_sources'] = ['tel_aviv_business_licenses'];
        $telAviv['source_counts'] = ['tel_aviv_business_licenses' => 0];
        $telAviv['source_requests'] = 0;
        $telAviv['source_errors'] = 0;
        foreach (['found', 'new', 'updated', 'duplicates', 'incomplete', 'failed', 'target_combinations'] as $metric) {
            $telAviv[$metric] = 0;
        }
        $telAviv['targets'] = [];
        $telAviv['errors'] = [];

        $reports = [$government, $telAviv];
        $logIds = [];
        foreach ($reports as $report) {
            $response = $this->postJson('/api/v1/business-import/worker-runs', $report)
                ->assertCreated()
                ->assertJsonPath('data.run_id', $report['run_id']);
            $logIds[$report['run_id']] = $response->json('data.id');
        }
        $this->assertNotSame($logIds[$government['run_id']], $logIds[$telAviv['run_id']]);

        foreach ($reports as $report) {
            $this->postJson('/api/v1/business-import/worker-runs', $report)
                ->assertOk()
                ->assertHeader('Idempotency-Replayed', 'true')
                ->assertJsonPath('data.id', $logIds[$report['run_id']]);
            $stored = SystemLogEntry::query()->where('external_id', $report['run_id'])->sole();
            $this->assertEquals($report, $stored->data);
        }
        $this->assertDatabaseCount('system_log_entries', 2);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach (array_reverse($reports) as $index => $report) {
            $response = $this->getJson('/api/v1/admin/logs?source=automation_worker&per_page=1&page='.($index + 1))
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 2)
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath('data.items.0.id', $logIds[$report['run_id']])
                ->assertJsonPath('data.items.0.external_id', $report['run_id']);
            foreach (['run_id', 'used_sources', 'source_counts', 'source_requests', 'source_errors', 'errors'] as $field) {
                $response->assertJsonPath('data.items.0.data.'.$field, $report[$field]);
            }
        }
    }

    public function test_only_write_clients_can_report_worker_runs(): void
    {
        $client = $this->businessClient([BusinessImportClient::SCOPE_READ]);
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ]);

        $this->postJson('/api/v1/business-import/worker-runs', $this->runReport())
            ->assertForbidden();
    }

    public function test_overture_progress_survives_admin_reads_and_remains_idempotent(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['used_sources'] = ['overture_places'];
        $report['source_counts'] = ['overture_places' => 9000];
        $report['overture_progress'] = [
            'release' => '2026-08-19.0', 'total' => 162913, 'scanned' => 9100,
            'remaining' => 153813, 'pending' => 50, 'failed' => 50,
        ];
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertCreated();
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertOk()->assertJsonPath('data.replayed', true);
        foreach (array_keys($report['overture_progress']) as $field) {
            $changed = $report;
            $changed['overture_progress'][$field] = $field === 'release' ? 'next-release' : $changed['overture_progress'][$field] + 1;
            $this->postJson('/api/v1/business-import/worker-runs', $changed)
                ->assertStatus(409)->assertJsonValidationErrors('run_id');
        }
        $this->assertDatabaseCount('system_log_entries', 1);
        $this->assertSame($report['overture_progress'], SystemLogEntry::query()->sole()->data['overture_progress']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/logs?source=automation_worker')->assertOk()
            ->assertJsonPath('data.items.0.data.overture_progress', $report['overture_progress']);
    }

    public function test_overture_progress_rejects_invalid_values(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['overture_progress'] = ['release' => '', 'total' => 10, 'scanned' => 11, 'remaining' => -1, 'pending' => -1, 'failed' => -1];
        $this->postJson('/api/v1/business-import/worker-runs', $report)->assertUnprocessable()
            ->assertJsonValidationErrors([
                'overture_progress.release', 'overture_progress.scanned', 'overture_progress.remaining', 'overture_progress.pending', 'overture_progress.failed',
            ]);
        $this->assertDatabaseCount('system_log_entries', 0);
    }

    public function test_clean_import_only_run_can_report_empty_source_and_error_lists(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $report = $this->runReport();
        $report['command'] = 'import';
        $report['target_combinations'] = 0;
        $report['targets'] = [];
        $report['used_sources'] = [];
        $report['source_counts'] = [];
        $report['errors'] = [];
        $report['failed'] = 0;
        $report['incomplete'] = 0;

        $this->postJson('/api/v1/business-import/worker-runs', $report)
            ->assertCreated();

        $this->assertDatabaseHas('system_log_entries', [
            'external_id' => $report['run_id'],
            'status' => SystemLogEntry::STATUS_SUCCESS,
        ]);
    }

    public function test_admin_can_filter_paginated_logs_and_regular_users_cannot_read_them(): void
    {
        SystemLogEntry::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => SystemLogEntry::SOURCE_AUTOMATION_WORKER,
            'type' => SystemLogEntry::TYPE_BUSINESS_IMPORT_RUN,
            'status' => SystemLogEntry::STATUS_SUCCESS,
            'external_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'data' => $this->runReport(),
            'occurred_at' => now(),
        ]);
        SystemLogEntry::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => 'mail',
            'type' => 'delivery_cycle',
            'status' => SystemLogEntry::STATUS_FAILED,
            'external_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64),
            'data' => ['failed' => 1],
            'occurred_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/logs')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/logs?source=automation_worker&status=success')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.source', 'automation_worker')
            ->assertJsonPath('data.items.0.type', 'business_import_run')
            ->assertJsonPath('data.items.0.data.imported', 10)
            ->assertJsonPath('data.filters.sources.0', 'automation_worker')
            ->assertJsonPath('data.filters.sources.1', 'mail');
    }

    private function businessClient(array $allowedScopes = [BusinessImportClient::SCOPE_WRITE]): Client
    {
        $client = Client::factory()->asClientCredentials()->create();
        BusinessImportClient::query()->create([
            'oauth_client_id' => $client->getKey(),
            'name' => 'Test Automation Worker',
            'allowed_scopes' => $allowedScopes,
            'active' => true,
        ]);

        return $client;
    }

    private function runReport(): array
    {
        return [
            'run_id' => (string) Str::uuid(),
            'command' => 'run',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'duration_seconds' => 60.125,
            'found' => 24,
            'new' => 10,
            'existing' => 14,
            'updated' => 2,
            'duplicates' => 3,
            'incomplete' => 1,
            'failed' => 1,
            'imported' => 10,
            'planned_imports' => 0,
            'planned_updates' => 0,
            'target_combinations' => 2,
            'targets' => [
                [
                    'key' => 'Tel Aviv|professionals.electricians',
                    'city' => 'Tel Aviv',
                    'category_key' => 'professionals.electricians',
                    'found' => 12,
                ],
                [
                    'key' => 'Jerusalem|professionals.electricians',
                    'city' => 'Jerusalem',
                    'category_key' => 'professionals.electricians',
                    'found' => 12,
                ],
            ],
            'used_sources' => ['data_gov_ckan'],
            'source_counts' => ['data_gov_ckan' => 24],
            'errors' => [
                ['stage' => 'import', 'message' => 'One row was invalid.', 'context' => []],
            ],
        ];
    }
}
