<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;

final class ImportService
{
    public function __construct(
        private readonly SveeveeGateway $api,
        private readonly WorkerRepository $repository,
        private readonly BusinessMerger $merger,
        private readonly Logger $logger,
        private readonly int $batchSize,
        private readonly ?int $maximumNewPerDay,
    ) {}

    public function import(string $runId, int $limit, bool $dryRun, RunReport $report): void
    {
        if (! $dryRun && ! $this->resumePendingBatches($report)) {
            return;
        }

        $ready = [];
        $plannedCreates = 0;
        $createdToday = $this->repository->countNewImportsToday();
        foreach ($this->repository->pendingBusinesses(max(1, $limit)) as $business) {
            $payload = $business['payload'];
            try {
                $duplicate = $this->api->checkDuplicate($payload);
                $matches = is_array($duplicate['matches'] ?? null) ? $duplicate['matches'] : [];
                if ($matches === []) {
                    $isCreate = ! isset($payload['id']);
                    if ($isCreate && $this->maximumNewPerDay !== null
                        && $createdToday + $plannedCreates >= $this->maximumNewPerDay) {
                        $this->logger->info('Daily new-business limit reached; remaining candidates stay pending.');
                        break;
                    }
                    $ready[] = ['business_id' => $business['id'], 'payload' => $payload];
                    $report->increment($isCreate ? 'planned_imports' : 'planned_updates');
                    if ($isCreate) {
                        $plannedCreates++;
                    }
                } else {
                    $report->increment('existing');
                    $report->increment('duplicates');
                    [$remote, $reason] = $this->resolveRemote($payload, $matches);
                    if ($remote === null) {
                        $message = $reason ?? 'Matching Sveevee page could not be resolved safely.';
                        if (! $dryRun) {
                            $this->repository->markBusiness(
                                $business['id'], 'failed', errorCode: 'duplicate_unresolved',
                                errorMessage: $message, matches: $matches
                            );
                        }
                        $report->increment('failed');
                        $report->error('duplicate_resolution', $message, ['business_id' => $business['id']]);
                        continue;
                    }
                    if (($remote['can_update'] ?? $remote['is_unclaimed'] ?? false) !== true) {
                        if (! $dryRun) {
                            $this->repository->markBusiness(
                                $business['id'], 'claimed', (int) $remote['id'],
                                'claimed', 'Claimed business pages cannot be changed.', $matches
                            );
                        }
                        continue;
                    }

                    $patch = $this->merger->patchForRemote($payload, $remote);
                    if (! $this->merger->hasRemoteChanges($patch)) {
                        if (! $dryRun) {
                            $this->repository->markBusiness(
                                $business['id'], 'duplicate', (int) $remote['id'],
                                null, null, $matches
                            );
                        }
                        continue;
                    }
                    $ready[] = ['business_id' => $business['id'], 'payload' => $patch];
                    $report->increment('planned_updates');
                }

                if (count($ready) >= $this->batchSize) {
                    if ($dryRun) {
                        $ready = [];
                    } elseif (! $this->sendNewBatch($runId, $ready, $report)) {
                        return;
                    } else {
                        $ready = [];
                    }
                }
            } catch (ApiException $exception) {
                if (! $dryRun) {
                    $this->repository->markBusiness(
                        $business['id'], 'failed', errorCode: $exception->reason,
                        errorMessage: $exception->getMessage()
                    );
                }
                $report->increment('failed');
                $report->error('api_check', $exception->getMessage(), [
                    'business_id' => $business['id'],
                    'status' => $exception->status,
                    'reason' => $exception->reason,
                    'request_id' => $exception->requestId,
                    'errors' => $exception->errors,
                ]);
            }
        }

        if (! $dryRun && $ready !== []) {
            $this->sendNewBatch($runId, $ready, $report);
        }
    }

    private function resumePendingBatches(RunReport $report): bool
    {
        foreach ($this->repository->pendingBatches() as $batch) {
            if (! $this->processBatch($batch, $report)) {
                return false;
            }
        }

        return true;
    }

    private function sendNewBatch(string $runId, array $items, RunReport $report): bool
    {
        $batch = $this->repository->createBatch($runId, $items);
        $batch['items'] = array_map(
            static fn (array $item, int $index): array => [
                'position' => $index + 1,
                'business_id' => $item['business_id'],
            ],
            $items,
            array_keys($items)
        );

        return $this->processBatch($batch, $report);
    }

    private function processBatch(array $batch, RunReport $report): bool
    {
        $batchId = (string) $batch['client_import_id'];
        $this->repository->markBatchAttempt($batchId);
        try {
            $response = $this->api->importBatch($batch['request']);
        } catch (ApiException $exception) {
            $this->repository->failBatch($batchId, $exception->getMessage(), $exception->retryable);
            $report->increment('failed', count($batch['items']));
            $report->error('batch', $exception->getMessage(), [
                'client_import_id' => $batchId,
                'status' => $exception->status,
                'reason' => $exception->reason,
                'request_id' => $exception->requestId,
                'retryable' => $exception->retryable,
                'errors' => $exception->errors,
            ]);
            $this->logger->error('Sveevee batch import failed.', [
                'client_import_id' => $batchId,
                'status' => $exception->status,
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
            ]);
            if (! $exception->retryable) {
                foreach ($batch['items'] as $item) {
                    $this->repository->markBusiness(
                        (int) $item['business_id'], 'failed', errorCode: $exception->reason,
                        errorMessage: $exception->getMessage()
                    );
                }
            }

            return false;
        }

        $responseItems = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['position'])) {
                $responseItems[(int) $item['position']] = $item;
            }
        }
        foreach ($batch['items'] as $localItem) {
            $position = (int) $localItem['position'];
            $businessId = (int) $localItem['business_id'];
            $item = $responseItems[$position] ?? null;
            if ($item === null) {
                $this->repository->markBusiness(
                    $businessId, 'failed', errorCode: 'invalid_response',
                    errorMessage: 'Batch response did not include this position.'
                );
                $report->increment('failed');
                continue;
            }

            $status = (string) ($item['status'] ?? 'failed');
            $pageId = isset($item['business']['id']) ? (int) $item['business']['id'] : null;
            if ($status === 'created') {
                $this->repository->markBusiness($businessId, 'imported', $pageId, operation: 'created');
                $report->increment('imported');
            } elseif ($status === 'updated') {
                $this->repository->markBusiness($businessId, 'updated', $pageId, operation: 'updated');
                $report->increment('updated');
            } elseif ($status === 'duplicate') {
                $matches = is_array($item['matches'] ?? null) ? $item['matches'] : [];
                $matchId = isset($matches[0]['id']) ? (int) $matches[0]['id'] : null;
                $this->repository->markBusiness($businessId, 'duplicate', $matchId, matches: $matches);
                $report->increment('duplicates');
                $report->increment('existing');
            } elseif ($status === 'claimed') {
                $this->repository->markBusiness(
                    $businessId, 'claimed', errorCode: 'claimed',
                    errorMessage: (string) ($item['message'] ?? 'Claimed business cannot be updated.')
                );
                $report->increment('existing');
            } else {
                $message = (string) ($item['message'] ?? 'Business import failed.');
                $errorDetails = $item['errors'] ?? null;
                if ($errorDetails !== null) {
                    $message .= ' '.json_encode($errorDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $localStatus = $status === 'invalid' ? 'invalid' : ($status === 'not_found' ? 'not_found' : 'failed');
                $this->repository->markBusiness($businessId, $localStatus, errorCode: $status, errorMessage: $message);
                $report->increment('failed');
                $report->error('batch_item', $message, [
                    'client_import_id' => $batchId,
                    'position' => $position,
                    'status' => $status,
                ]);
            }
        }
        $this->repository->completeBatch($batchId, $response);

        return true;
    }

    private function resolveRemote(array $candidate, array $matches): array
    {
        usort($matches, fn (array $left, array $right): int => $this->matchScore($right) <=> $this->matchScore($left));
        $selected = $matches[0] ?? null;
        if (! is_array($selected)) {
            return [null, 'Duplicate response did not contain a usable match.'];
        }
        if (isset($matches[1]) && $this->matchScore($matches[1]) === $this->matchScore($selected)) {
            return [null, 'Multiple equally strong duplicate matches require manual review.'];
        }

        $filters = [];
        $matchedOn = (array) ($selected['matched_on'] ?? []);
        if (in_array('contact_email', $matchedOn, true) && isset($candidate['contact_email'])) {
            $filters[] = ['contact_email' => $candidate['contact_email'], 'per_page' => 100];
        }
        if (in_array('phone', $matchedOn, true) && isset($candidate['phone'])) {
            $filters[] = ['phone' => $candidate['phone'], 'per_page' => 100];
        }
        $filters[] = array_filter([
            'name' => $candidate['name'] ?? null,
            'city' => $candidate['address']['city'] ?? null,
            'per_page' => 100,
        ], static fn ($value) => $value !== null && $value !== '');

        foreach ($filters as $filter) {
            $result = $this->api->searchBusinesses($filter);
            foreach ((array) ($result['businesses'] ?? []) as $business) {
                if (is_array($business) && (int) ($business['id'] ?? 0) === (int) ($selected['id'] ?? 0)) {
                    return [$business, null];
                }
            }
        }

        return [null, 'Matching page disappeared or could not be returned by the search API.'];
    }

    private function matchScore(array $match): int
    {
        $signals = (array) ($match['matched_on'] ?? []);
        $weights = ['contact_email' => 100, 'phone' => 90, 'website' => 70, 'address' => 60, 'name' => 40];

        return array_sum(array_map(static fn (string $signal): int => $weights[$signal] ?? 0, $signals));
    }
}
