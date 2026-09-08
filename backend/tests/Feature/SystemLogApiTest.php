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
    }

    public function test_only_write_clients_can_report_worker_runs(): void
    {
        $client = $this->businessClient([BusinessImportClient::SCOPE_READ]);
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ]);

        $this->postJson('/api/v1/business-import/worker-runs', $this->runReport())
            ->assertForbidden();
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
