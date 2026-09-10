<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessImportException;
use App\Exceptions\BusinessImportReviewException;
use App\Exceptions\ExactPageDuplicateException;
use App\Http\Controllers\Controller;
use App\Models\BusinessImportBatch;
use App\Models\BusinessImportIdempotencyKey;
use App\Models\Page;
use App\Services\ApiResponseService;
use App\Services\BusinessImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class BusinessImportController extends Controller
{
    public function __construct(private readonly BusinessImportService $businesses) {}

    public function index(Request $request)
    {
        return ApiResponseService::success($this->businesses->search($request->query()));
    }

    public function duplicateCheck(Request $request)
    {
        $ids = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'exclude_id' => ['nullable', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);
        try {
            $matches = $this->businesses->duplicateMatches(
                $request->except('exclude_id', 'dry_run'),
                (int) ($ids['exclude_id'] ?? $ids['id'] ?? 0) ?: null
            );
        } catch (BusinessImportException $exception) {
            return $this->businessImportError($exception, persistReview: ! $request->boolean('dry_run'));
        }

        return ApiResponseService::success([
            'duplicate' => $matches !== [],
            'matches' => $matches,
        ]);
    }

    public function store(Request $request)
    {
        try {
            if (! filled($request->input('id'))) {
                return $this->storeIdempotently($request);
            }

            $result = $this->businesses->upsert($this->clientId($request), $request->all());
        } catch (ExactPageDuplicateException $exception) {
            return $this->duplicateResponse($exception);
        } catch (BusinessImportException $exception) {
            return $this->businessImportError($exception);
        }

        $created = $result['operation'] === 'created';

        return ApiResponseService::success(
            $result,
            $created ? 'Business created.' : 'Business updated.',
            $created ? 201 : 200
        );
    }

    private function storeIdempotently(Request $request)
    {
        $idempotencyKey = Validator::make([
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'idempotency_key' => ['required', 'uuid'],
        ])->validate()['idempotency_key'];
        $clientId = $this->clientId($request);
        $payload = $request->all();
        $requestHash = $this->businesses->payloadHash($payload);

        $outcome = DB::transaction(function () use ($clientId, $idempotencyKey, $payload, $requestHash): array {
            $key = BusinessImportIdempotencyKey::query()->firstOrCreate([
                'oauth_client_id' => $clientId,
                'idempotency_key' => $idempotencyKey,
            ], [
                'request_hash' => $requestHash,
                'status' => 'processing',
            ]);

            if (! $key->wasRecentlyCreated) {
                if (! hash_equals($key->request_hash, $requestHash)) {
                    throw new BusinessImportException(
                        'The Idempotency-Key was already used for a different payload.',
                        409,
                        'idempotency_key'
                    );
                }
                if ($key->status !== 'completed' || ! is_array($key->result)) {
                    throw new BusinessImportException(
                        'This Idempotency-Key is already being processed.',
                        409,
                        'idempotency_key'
                    );
                }

                return [
                    'result' => $key->result,
                    'replayed' => true,
                ];
            }

            $result = $this->businesses->upsert($clientId, $payload);
            $key->update([
                'status' => 'completed',
                'response_status' => $result['operation'] === 'created' ? 201 : 200,
                'result' => $result,
            ]);

            return [
                'result' => $result,
                'replayed' => false,
            ];
        }, 3);

        $response = ApiResponseService::success(
            [...$outcome['result'], 'replayed' => $outcome['replayed']],
            $outcome['replayed'] ? 'Import already processed.' : ($outcome['result']['operation'] === 'created' ? 'Business created.' : 'Business updated.'),
            $outcome['replayed'] || $outcome['result']['operation'] !== 'created' ? 200 : 201
        );
        $response->headers->set('Idempotency-Replayed', $outcome['replayed'] ? 'true' : 'false');

        return $response;
    }

    public function update(Request $request, Page $page)
    {
        try {
            $business = $this->businesses->update($this->clientId($request), $page, $request->all());
        } catch (ExactPageDuplicateException $exception) {
            return $this->duplicateResponse($exception);
        } catch (BusinessImportException $exception) {
            return $this->businessImportError($exception);
        }

        return ApiResponseService::success([
            'operation' => 'updated',
            'business' => $business,
        ], 'Business updated.');
    }

    public function batch(Request $request)
    {
        $maxRows = max(100, min(1000, (int) config('business_import.max_batch_size', 1000)));
        $data = $request->validate([
            'client_import_id' => ['required', 'uuid'],
            'businesses' => ['required', 'array', 'min:1', 'max:'.$maxRows],
            'businesses.*' => ['required', 'array'],
        ]);
        $clientId = $this->clientId($request);
        $requestHash = $this->businesses->payloadHash($data['businesses']);
        $batch = BusinessImportBatch::query()->firstOrCreate([
            'oauth_client_id' => $clientId,
            'client_import_id' => $data['client_import_id'],
        ], [
            'request_hash' => $requestHash,
            'status' => 'processing',
            'input_count' => count($data['businesses']),
        ]);

        if (! hash_equals($batch->request_hash, $requestHash)) {
            return ApiResponseService::error(
                'The client_import_id was already used for a different payload.',
                ['client_import_id' => ['Use a new UUID for a different batch.']],
                409
            );
        }

        try {
            do {
                $outcome = DB::transaction(function () use ($batch, $clientId, $requestHash, $data): array {
                    // Commit each page and its checkpoint together. The row lock makes retries
                    // agree on the next position without keeping an entire batch uncommitted.
                    $batch = BusinessImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                    if (! hash_equals($batch->request_hash, $requestHash)) {
                        throw new BusinessImportException('The client_import_id was already used for a different payload.', 409, 'client_import_id');
                    }
                    if ($batch->status === 'completed') {
                        if (! is_array($batch->result)) {
                            throw new BusinessImportException('The completed batch result is unavailable.', 409, 'batch_state');
                        }

                        return ['result' => $batch->result, 'replayed' => true, 'completed' => true];
                    }
                    // Failed or interrupted legacy batches may already have committed some pages.
                    // Their original source IDs and explicit page IDs remain authoritative on retry.
                    $items = $batch->result['items'] ?? [];
                    if ($batch->result !== null && (
                        ($batch->result['checkpoint_version'] ?? null) !== 1
                        || ! is_array($items) || ! array_is_list($items)
                        || count($items) >= count($data['businesses'])
                    )) {
                        throw new BusinessImportException('The batch checkpoint is invalid.', 409, 'batch_state');
                    }
                    $counts = ['created_count' => 0, 'updated_count' => 0, 'duplicate_count' => 0, 'invalid_count' => 0, 'conflict_count' => 0];
                    foreach ($items as $index => $item) {
                        if (! is_array($item) || ($item['position'] ?? null) !== $index + 1 || ! is_string($item['status'] ?? null)) {
                            throw new BusinessImportException('The batch checkpoint is invalid.', 409, 'batch_state');
                        }
                        $counter = in_array($item['status'], ['created', 'updated', 'duplicate', 'invalid'], true) ? $item['status'].'_count' : 'conflict_count';
                        $counts[$counter]++;
                    }
                    $batch->update(['status' => 'processing']);
                    $position = count($items) + 1;
                    $input = array_values($data['businesses'])[$position - 1];
                    try {
                        $result = $this->businesses->upsert($clientId, $input);
                        $counts[$result['operation'].'_count']++;
                        $items[] = ['position' => $position, 'status' => $result['operation'], ...$result];
                    } catch (ExactPageDuplicateException $exception) {
                        $counts['duplicate_count']++;
                        $items[] = ['position' => $position, 'status' => 'duplicate', 'matches' => $exception->matches];
                    } catch (ValidationException $exception) {
                        $counts['invalid_count']++;
                        $items[] = ['position' => $position, 'status' => 'invalid', 'errors' => $exception->errors()];
                    } catch (BusinessImportException $exception) {
                        if ($exception->status >= 500 || $exception->status === 429) {
                            throw $exception;
                        }
                        $counts['conflict_count']++;
                        $review = $exception instanceof BusinessImportReviewException ? $this->businesses->recordMatchReview($exception) : [];
                        $items[] = ['position' => $position, 'status' => $exception->reason, 'message' => $exception->getMessage(), ...$review];
                    }
                    $result = [
                        'client_import_id' => $data['client_import_id'], 'input_count' => count($data['businesses']),
                        ...$counts, 'items' => $items, 'replayed' => false,
                    ];
                    $completed = count($items) === count($data['businesses']);
                    $batch->update([
                        'status' => $completed ? 'completed' : 'processing', ...$counts,
                        'result' => $completed ? $result : ['checkpoint_version' => 1, ...$result],
                    ]);

                    return ['result' => $result, 'replayed' => false, 'completed' => $completed];
                }, 3);
            } while (! $outcome['completed']);
        } catch (Throwable $exception) {
            try {
                // Another waiting request may have recovered this batch after our rollback.
                // Never replace that committed result with a stale failure status.
                BusinessImportBatch::query()->whereKey($batch->id)->where('request_hash', $requestHash)
                    ->where('status', '!=', 'completed')->update(['status' => 'failed']);
            } catch (Throwable $statusError) {
                report($statusError);
            }
            if ($exception instanceof BusinessImportException) {
                return $this->businessImportError($exception);
            }

            throw $exception;
        }

        return ApiResponseService::success(
            [...$outcome['result'], 'replayed' => $outcome['replayed']],
            $outcome['replayed'] ? 'Import already processed.' : 'Batch import completed.',
            $outcome['replayed'] ? 200 : 201
        );
    }

    private function clientId(Request $request): string
    {
        return (string) $request->attributes->get('oauth_client_id');
    }

    private function duplicateResponse(ExactPageDuplicateException $exception)
    {
        return ApiResponseService::error(
            'A matching business already exists.',
            ['duplicate' => ['Review the returned matches before updating an existing business.']],
            409,
            ['matches' => $exception->matches]
        );
    }

    private function businessImportError(BusinessImportException $exception, bool $persistReview = true)
    {
        // Store-level idempotency transactions have rolled back before this handler runs.
        $review = $exception instanceof BusinessImportReviewException
            ? ($persistReview ? $this->businesses->recordMatchReview($exception) : ['review_id' => null, 'reason' => $exception->reviewReason])
            : null;

        return ApiResponseService::error(
            $exception->getMessage(),
            [$exception->reason => [$exception->getMessage()]],
            $exception->status,
            $review
        );
    }
}
