<?php

namespace Tests\Feature;

use App\Exceptions\BusinessImportException;
use App\Models\BusinessImportBatch;
use App\Models\BusinessImportClient;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use App\Services\BusinessImportService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BusinessImportBatchRecoveryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $client = Client::factory()->asClientCredentials()->create();
        $this->clientId = $client->getKey();
        BusinessImportClient::query()->create([
            'oauth_client_id' => $this->clientId, 'name' => 'Batch recovery test',
            'allowed_scopes' => [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE], 'active' => true,
        ]);
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
    }

    public function test_an_unexpected_failure_rolls_back_only_the_current_page_and_resumes_after_the_committed_checkpoint(): void
    {
        $service = app(BusinessImportService::class);
        $calls = 0;
        $baselineTransactionLevel = DB::transactionLevel();
        $this->partialMock(BusinessImportService::class, function (MockInterface $mock) use ($service, &$calls, $baselineTransactionLevel): void {
            $mock->shouldReceive('upsert')->andReturnUsing(function (string $clientId, array $input) use ($service, &$calls, $baselineTransactionLevel): array {
                $this->assertGreaterThan($baselineTransactionLevel, DB::transactionLevel());
                $result = $service->upsert($clientId, $input);
                if (++$calls === 2) {
                    throw new RuntimeException('Fixture failed after a source upsert.');
                }

                return $result;
            });
        });
        $request = ['client_import_id' => (string) Str::uuid(), 'businesses' => [$this->sourcePayload('atomic-1'), $this->sourcePayload('atomic-2')]];
        $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertStatus(500);
        $batch = BusinessImportBatch::query()->sole();
        $batchId = $batch->id;
        $this->assertSame('failed', $batch->status);
        $checkpoint = $batch->result;
        $this->assertSame(1, $checkpoint['checkpoint_version']);
        $this->assertCount(1, $checkpoint['items']);
        $this->assertSame('created', $checkpoint['items'][0]['status']);
        foreach (['pages', 'business_import_sources', 'business_import_pages', 'business_import_cities', 'business_import_categories'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $response = $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertCreated()
            ->assertJsonPath('data.created_count', 2)->assertJsonPath('data.updated_count', 0)->assertJsonPath('data.replayed', false);
        $this->assertSame($batchId, BusinessImportBatch::query()->sole()->id);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame($checkpoint['items'][0], $response->json('data.items.0'));
        $this->assertArrayNotHasKey('checkpoint_version', $batch->fresh()->result);
        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('business_import_sources', 2);
        $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.items', $response->json('data.items'));
        $this->assertSame(3, $calls, 'A checkpointed or completed replay invoked the source upsert again.');
    }

    public function test_failed_and_interrupted_legacy_batches_reuse_previously_committed_source_pages(): void
    {
        $service = app(BusinessImportService::class);
        foreach (['failed', 'processing'] as $status) {
            $rows = [$this->sourcePayload($status.'-old'), $this->sourcePayload($status.'-new')];
            $existing = $service->upsert($this->clientId, $rows[0])['business'];
            $batch = $this->seedBatch($rows, $status);
            $request = ['client_import_id' => $batch->client_import_id, 'businesses' => $rows];
            $response = $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertCreated()
                ->assertJsonPath('data.client_import_id', $batch->client_import_id)
                ->assertJsonPath('data.created_count', 1)->assertJsonPath('data.updated_count', 1)
                ->assertJsonPath('data.items.0.business.id', $existing['id'])->assertJsonPath('data.items.0.status', 'updated');
            $this->assertSame('completed', $batch->fresh()->status);
            $this->assertSame($existing['id'], BusinessImportSource::where('source_id', $rows[0]['source']['id'])->sole()->page_id);
            $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertOk()
                ->assertJsonPath('data.replayed', true)->assertJsonPath('data.items', $response->json('data.items'));
        }
        $this->assertDatabaseCount('business_import_batches', 2);
        $this->assertDatabaseCount('pages', 4);
        $this->assertDatabaseCount('business_import_sources', 4);
    }

    public function test_legacy_recovery_does_not_overwrite_a_page_claimed_since_the_partial_import(): void
    {
        $rows = [$this->sourcePayload('claimed-old'), $this->sourcePayload('claimed-new')];
        $existing = app(BusinessImportService::class)->upsert($this->clientId, $rows[0])['business'];
        $owner = User::factory()->create();
        $page = Page::findOrFail($existing['id']);
        $page->update(['user_id' => $owner->id, 'is_unclaimed' => false, 'phone' => '03-1234567']);
        $batch = $this->seedBatch($rows, 'failed');
        $this->postJson('/api/v1/business-import/businesses/batch', ['client_import_id' => $batch->client_import_id, 'businesses' => $rows])
            ->assertCreated()->assertJsonPath('data.items.0.status', 'claimed')
            ->assertJsonPath('data.conflict_count', 1)->assertJsonPath('data.created_count', 1);
        $this->assertSame($owner->id, $page->fresh()->user_id);
        $this->assertSame('03-1234567', $page->fresh()->phone);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_legacy_regular_creates_are_reported_as_duplicates_without_replacing_existing_details(): void
    {
        $rows = [[
            'name' => 'Previously imported regular business', 'category_key' => 'food_catering.cafes',
            'address' => ['city' => 'Tel Aviv', 'street' => 'Main Street', 'number' => '10'],
        ], [
            'name' => 'Remaining regular business', 'category_key' => 'food_catering.cafes',
            'address' => ['city' => 'Tel Aviv', 'street' => 'Main Street', 'number' => '11'],
        ]];
        $existing = app(BusinessImportService::class)->upsert($this->clientId, $rows[0])['business'];
        Page::findOrFail($existing['id'])->update(['phone' => '03-1234567']);
        $batch = $this->seedBatch($rows, 'failed');
        $this->postJson('/api/v1/business-import/businesses/batch', ['client_import_id' => $batch->client_import_id, 'businesses' => $rows])
            ->assertCreated()->assertJsonPath('data.duplicate_count', 1)->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.items.0.status', 'duplicate')->assertJsonPath('data.items.0.matches.0.id', $existing['id']);
        $this->assertSame('03-1234567', Page::findOrFail($existing['id'])->phone);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_failed_batch_payload_changes_are_rejected_without_resetting_or_processing_the_batch(): void
    {
        $rows = [$this->sourcePayload('immutable-request')];
        $batch = $this->seedBatch($rows, 'failed');
        $before = $batch->fresh()->getAttributes();
        $rows[0]['name'] = 'Changed batch input';
        $this->postJson('/api/v1/business-import/businesses/batch', ['client_import_id' => $batch->client_import_id, 'businesses' => $rows])
            ->assertStatus(409)->assertJsonValidationErrors('client_import_id');
        $this->assertSame($before, $batch->fresh()->getAttributes());
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_transient_service_errors_do_not_become_permanent_completed_item_results(): void
    {
        $service = app(BusinessImportService::class);
        $calls = 0;
        $this->partialMock(BusinessImportService::class, function (MockInterface $mock) use ($service, &$calls): void {
            $mock->shouldReceive('upsert')->andReturnUsing(function (string $clientId, array $input) use ($service, &$calls): array {
                if (++$calls === 2) {
                    throw new BusinessImportException('Fixture service temporarily unavailable.', 503, 'service_unavailable');
                }

                return $service->upsert($clientId, $input);
            });
        });
        $request = ['client_import_id' => (string) Str::uuid(), 'businesses' => [$this->sourcePayload('transient-1'), $this->sourcePayload('transient-2')]];
        $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertStatus(503);
        $this->assertSame('failed', BusinessImportBatch::query()->sole()->status);
        $this->assertDatabaseCount('pages', 1);
        $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertCreated()->assertJsonPath('data.created_count', 2);
        $this->assertSame(3, $calls);
    }

    public function test_interleaved_same_uuid_requests_continue_from_committed_positions_and_share_one_result(): void
    {
        $request = ['client_import_id' => (string) Str::uuid(), 'businesses' => [
            $this->sourcePayload('interleaved-1'), $this->sourcePayload('interleaved-2'), $this->sourcePayload('interleaved-3'),
        ]];
        $service = app(BusinessImportService::class);
        $calls = 0;
        $this->partialMock(BusinessImportService::class, function (MockInterface $mock) use ($service, &$calls): void {
            $mock->shouldReceive('upsert')->andReturnUsing(function (string $clientId, array $input) use ($service, &$calls): array {
                $calls++;

                return $service->upsert($clientId, $input);
            });
        });
        $baselineTransactionLevel = DB::transactionLevel();
        $secondRequestStarted = false;
        $secondResponse = null;
        // Simulate another request taking the batch row lock between two item commits.
        Event::listen(TransactionCommitted::class, function () use ($request, $baselineTransactionLevel, &$secondRequestStarted, &$secondResponse): void {
            if ($secondRequestStarted || DB::transactionLevel() !== $baselineTransactionLevel) {
                return;
            }
            $batch = BusinessImportBatch::where('client_import_id', $request['client_import_id'])->first();
            if ($batch?->status !== 'processing' || count($batch->result['items'] ?? []) !== 1) {
                return;
            }
            $secondRequestStarted = true;
            $secondResponse = $this->postJson('/api/v1/business-import/businesses/batch', $request);
        });
        $response = $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.created_count', 3);
        $this->assertTrue($secondRequestStarted);
        $secondResponse->assertCreated()->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.items', $response->json('data.items'));
        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('pages', 3);
        $this->assertDatabaseCount('business_import_sources', 3);
        $this->assertDatabaseCount('business_import_batches', 1);
    }

    public function test_a_late_failure_cannot_replace_a_result_completed_after_its_transaction_rolled_back(): void
    {
        $rows = [$this->sourcePayload('late-failure')];
        $batch = $this->seedBatch($rows, 'failed');
        $completedResult = ['client_import_id' => $batch->client_import_id, 'input_count' => 1, 'items' => [], 'replayed' => false];
        $baselineTransactionLevel = DB::transactionLevel();
        $completedAfterRollback = false;
        // Simulate a waiting request committing after the first request releases its row lock.
        Event::listen(TransactionRolledBack::class, function () use ($batch, $completedResult, $baselineTransactionLevel, &$completedAfterRollback): void {
            if (! $completedAfterRollback && DB::transactionLevel() === $baselineTransactionLevel) {
                $completedAfterRollback = true;
                BusinessImportBatch::whereKey($batch->id)->update(['status' => 'completed', 'result' => $completedResult]);
            }
        });
        $this->partialMock(BusinessImportService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('upsert')->once()->andThrow(new RuntimeException('Fixture request failed.'));
        });
        $this->postJson('/api/v1/business-import/businesses/batch', ['client_import_id' => $batch->client_import_id, 'businesses' => $rows])->assertStatus(500);
        $this->assertTrue($completedAfterRollback);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame($completedResult, $batch->fresh()->result);
    }

    private function seedBatch(array $rows, string $status): BusinessImportBatch
    {
        return BusinessImportBatch::query()->create([
            'oauth_client_id' => $this->clientId, 'client_import_id' => (string) Str::uuid(),
            'request_hash' => app(BusinessImportService::class)->payloadHash($rows),
            'status' => $status, 'input_count' => count($rows),
        ]);
    }

    private function sourcePayload(string $id): array
    {
        return [
            'name' => 'Batch source fixture '.$id, 'address' => ['city' => 'Unknown locality '.$id],
            'source' => [
                'provider' => 'overture_places', 'id' => $id,
                'url' => 'https://explore.overturemaps.org/?feature=places.place.'.$id,
                'metadata' => [
                    'original_name' => 'Batch source fixture '.$id, 'source_city' => 'Unknown locality '.$id,
                    'source_categories' => [['key' => 'unknown_type_'.$id, 'label' => 'Unknown category '.$id, 'catalog_key' => null]],
                ],
            ],
        ];
    }
}
