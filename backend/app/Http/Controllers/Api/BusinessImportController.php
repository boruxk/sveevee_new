<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessImportException;
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
        ]);
        $matches = $this->businesses->duplicateMatches(
            $request->except('exclude_id'),
            (int) ($ids['exclude_id'] ?? $ids['id'] ?? 0) ?: null
        );

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
                'response_status' => 201,
                'result' => $result,
            ]);

            return [
                'result' => $result,
                'replayed' => false,
            ];
        }, 3);

        $response = ApiResponseService::success(
            [...$outcome['result'], 'replayed' => $outcome['replayed']],
            $outcome['replayed'] ? 'Import already processed.' : 'Business created.',
            $outcome['replayed'] ? 200 : 201
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

        if (! $batch->wasRecentlyCreated) {
            if (! hash_equals($batch->request_hash, $requestHash)) {
                return ApiResponseService::error(
                    'The client_import_id was already used for a different payload.',
                    ['client_import_id' => ['Use a new UUID for a different batch.']],
                    409
                );
            }
            if ($batch->status !== 'completed' || ! is_array($batch->result)) {
                return ApiResponseService::error('This batch already exists but is not complete.', status: 409);
            }

            return ApiResponseService::success([
                ...$batch->result,
                'replayed' => true,
            ], 'Import already processed.');
        }
        $items = [];
        $counts = [
            'created_count' => 0,
            'updated_count' => 0,
            'duplicate_count' => 0,
            'invalid_count' => 0,
            'conflict_count' => 0,
        ];

        try {
            foreach (array_values($data['businesses']) as $index => $input) {
                $position = $index + 1;

                try {
                    $result = $this->businesses->upsert($clientId, $input);
                    $countKey = $result['operation'].'_count';
                    $counts[$countKey]++;
                    $items[] = ['position' => $position, 'status' => $result['operation'], ...$result];
                } catch (ExactPageDuplicateException $exception) {
                    $counts['duplicate_count']++;
                    $items[] = [
                        'position' => $position,
                        'status' => 'duplicate',
                        'matches' => $exception->matches,
                    ];
                } catch (ValidationException $exception) {
                    $counts['invalid_count']++;
                    $items[] = [
                        'position' => $position,
                        'status' => 'invalid',
                        'errors' => $exception->errors(),
                    ];
                } catch (BusinessImportException $exception) {
                    $counts['conflict_count']++;
                    $items[] = [
                        'position' => $position,
                        'status' => $exception->reason,
                        'message' => $exception->getMessage(),
                    ];
                }
            }

            $result = [
                'client_import_id' => $data['client_import_id'],
                'input_count' => count($data['businesses']),
                ...$counts,
                'items' => $items,
                'replayed' => false,
            ];
            $batch->update([
                'status' => 'completed',
                ...$counts,
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            $batch->update(['status' => 'failed']);

            throw $exception;
        }

        return ApiResponseService::success($result, 'Batch import completed.', 201);
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

    private function businessImportError(BusinessImportException $exception)
    {
        return ApiResponseService::error(
            $exception->getMessage(),
            [$exception->reason => [$exception->getMessage()]],
            $exception->status
        );
    }
}
