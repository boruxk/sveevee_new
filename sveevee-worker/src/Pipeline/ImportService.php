<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessLocationIdentity;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;

final class ImportService
{
    private array $seenBusinessIds = [];

    private ?ResearchTarget $fullSourceTarget = null;

    public function __construct(
        private readonly SveeveeGateway $api,
        private readonly WorkerRepository $repository,
        private readonly BusinessMerger $merger,
        private readonly Logger $logger,
        private readonly int $batchSize,
    ) {}

    public function import(string $runId, int $limit, bool $dryRun, RunReport $report): void
    {
        $targets = $this->repository->pendingTargets();
        $targets = (new ResearchTargetScheduler($this->repository))->next($targets, max(1, count($targets)));
        $this->runTargets($runId, $targets, $limit, 10, 100, $dryRun, $report);
    }

    /** Visit each scheduled combination once; only successful combinations use a slot. */
    public function runTargets(
        string $runId,
        array $targets,
        int $limit,
        int $productiveLimit,
        int $perCombination,
        bool $dryRun,
        RunReport $report,
        ?ResearchService $research = null,
    ): void {
        $this->seenBusinessIds = [];
        $this->fullSourceTarget = count($targets) === 1 && $targets[0]->isFullSource() ? $targets[0] : null;
        if (($this->fullSourceTarget !== null)) {
            $productiveLimit = 1;
            $perCombination = $limit;
            $report->source($this->fullSourceTarget->fullSourceProvider(), 0);
        }
        $budget = new RunBudget(max(1, $limit), max(1, $productiveLimit), max(1, $perCombination));
        if (! $this->resumePendingBatches($runId, $dryRun, $report, $budget)) {
            return;
        }

        $localTargets = null;
        foreach ($targets as $target) {
            if ($budget->remaining($target) === 0) {
                continue;
            }
            if ($research !== null && ! $research->canResearch()) {
                $localTargets ??= array_fill_keys(array_map(
                    static fn (ResearchTarget $pending): string => $pending->key(),
                    $this->repository->pendingTargets(),
                ), true);
                if (! isset($localTargets[$target->key()])) {
                    continue;
                }
            }
            $research?->deferPausedSources($target, $report);
            $foundBefore = $report->metric('found');
            $ready = [];
            $completed = false;
            try {
                foreach ($this->candidates($runId, $target, $report, $research) as $business) {
                    $item = $this->prepareBusiness($business, $dryRun, $report);
                    if ($item === null) {
                        continue;
                    }
                    if ($dryRun) {
                        $budget->record($target);
                        $report->target($target->key(), $target->city, $target->categoryKey, 0, 0, 1);
                    } else {
                        $ready[] = $item;
                        if (count($ready) >= min(max(1, $this->batchSize), 100, $budget->remaining($target))) {
                            if (! $this->sendNewBatch($runId, $ready, $report, $budget)) {
                                return;
                            }
                            $ready = [];
                        }
                    }
                    if ($budget->remaining($target) === 0) {
                        break;
                    }
                }
                if ($ready !== [] && ! $this->sendNewBatch($runId, $ready, $report, $budget)) {
                    return;
                }
                $completed = true;
            } finally {
                $report->target($target->key(), $target->city, $target->categoryKey, $report->metric('found') - $foundBefore);
                if ($completed && ! $dryRun && ! $research?->isTargetDeferred($target)) {
                    $this->repository->markResearchTargetCompleted($target, $runId);
                }
            }
        }
    }

    private function candidates(string $runId, ResearchTarget $target, RunReport $report, ?ResearchService $research): iterable
    {
        foreach ($this->repository->pendingForTarget($target) as $business) {
            if (! isset($this->seenBusinessIds[$business['id']])) {
                $this->seenBusinessIds[$business['id']] = true;
                yield $business;
            }
        }
        if ($research !== null) {
            foreach ($research->candidates($runId, $target, $report) as $business) {
                if (! isset($this->seenBusinessIds[$business['id']])) {
                    $this->seenBusinessIds[$business['id']] = true;
                    yield $business;
                }
            }
        }
    }

    private function prepareBusiness(array $business, bool $dryRun, RunReport $report): ?array
    {
        $payload = $business['payload'];
        // Older pending research also passes this filter before a new batch is created.
        // Already submitted retry requests keep their original body and idempotency ID.
        $payload['name'] = BusinessNormalizer::cleanBusinessName((string) ($payload['name'] ?? ''));
        if (trim($payload['name']) === '') {
            if (! $dryRun) {
                $this->repository->markBusiness($business['id'], 'invalid', errorCode: 'missing_name', errorMessage: 'No business name remains after removing the company suffix.');
            }
            $report->increment('incomplete');

            return null;
        }
        try {
            $provider = $payload['source']['provider'] ?? 'overture_places';
            $hasSource = in_array($provider, ['overture_places', 'data_gov_ckan', 'tel_aviv_business_licenses'], true) && $this->repository->hasSource($business['id'], $provider);
            $knownPageId = filter_var($business['sveevee_page_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $linkedSource = $hasSource && $knownPageId !== false;
            if ($linkedSource) {
                // A persisted source-to-page association survives a source name correction.
                // An absent, moved or claimed linked page must never trigger a replacement create.
                $matches = [['id' => $knownPageId, 'matched_on' => ['source_id']]];
                [$remote, $reason] = $this->resolveLinkedRemote($knownPageId);
            } else {
                // Legacy pages can still carry the suffix; retain their original lookup name.
                $lookupPayload = $business['payload'];
                $duplicate = $this->api->checkDuplicate($lookupPayload);
                $matches = is_array($duplicate['matches'] ?? null) ? $duplicate['matches'] : [];
                if ($matches === [] && $lookupPayload['name'] !== $payload['name']) {
                    $lookupPayload = $payload;
                    $duplicate = $this->api->checkDuplicate($lookupPayload);
                    $matches = is_array($duplicate['matches'] ?? null) ? $duplicate['matches'] : [];
                }
                if ($matches === []) {
                    $report->increment(isset($payload['id']) ? 'planned_updates' : 'planned_imports');

                    return ['business_id' => $business['id'], 'payload' => $payload, 'payload_hash' => $business['payload_hash'], 'target_city' => $payload['address']['city'] ?? '', 'target_category' => $payload['category_key'] ?? ''];
                }
                [$remote, $reason] = $this->resolveRemote($lookupPayload, $matches);
            }

            $report->increment('existing');
            $report->increment('duplicates');
            if ($remote !== null && ($hasSource || BusinessLocationIdentity::street($payload) !== '')) {
                $identityPayload = $payload;
                if ($linkedSource) {
                    $identityPayload['name'] = $remote['name'] ?? '';
                }
                $sourceLinked = isset($payload['source']) && ($linkedSource || count(array_filter($matches,
                    static fn (array $match): bool => (int) ($match['id'] ?? 0) === (int) $remote['id']
                        && in_array('source_id', (array) ($match['matched_on'] ?? []), true))) > 0);
                $conflict = $sourceLinked
                    ? BusinessLocationIdentity::sourceConflict($remote, $identityPayload)
                    : BusinessLocationIdentity::conflict($remote, $identityPayload);
                if ($conflict !== null) {
                    $remote = null;
                    $reason = $conflict;
                } else {
                    $payload = BusinessLocationIdentity::preserveAddressRepresentation($remote, $payload);
                }
            }
            if ($remote === null) {
                $message = $reason ?? 'Matching Sveevee page could not be resolved safely.';
                if (! $dryRun) {
                    $this->repository->markBusiness($business['id'], 'failed', errorCode: 'duplicate_unresolved', errorMessage: $message, matches: $matches);
                }
                $report->increment('failed');
                $report->error('duplicate_resolution', $message, ['business_id' => $business['id']]);

                return null;
            }
            if (($remote['can_update'] ?? $remote['is_unclaimed'] ?? false) !== true) {
                if (! $dryRun) {
                    $this->repository->markBusiness($business['id'], 'claimed', (int) $remote['id'], 'claimed', 'Claimed business pages cannot be changed.', $matches);
                }

                return null;
            }
            $patch = $this->merger->patchForRemote($payload, $remote);
            if (! $this->merger->hasRemoteChanges($patch)) {
                if (! $dryRun) {
                    $this->repository->markBusiness($business['id'], 'duplicate', (int) $remote['id'], null, null, $matches);
                }

                return null;
            }
            $report->increment('planned_updates');

            return ['business_id' => $business['id'], 'payload' => $patch, 'payload_hash' => $business['payload_hash'], 'target_city' => $payload['address']['city'] ?? '', 'target_category' => $payload['category_key'] ?? ''];
        } catch (ApiException $exception) {
            if (! $dryRun) {
                $this->repository->markBusiness($business['id'], 'failed', errorCode: $exception->reason, errorMessage: $exception->getMessage());
            }
            $report->increment('failed');
            $report->error('api_check', $exception->getMessage(), [
                'business_id' => $business['id'], 'status' => $exception->status,
                'reason' => $exception->reason, 'request_id' => $exception->requestId,
                'errors' => $exception->errors,
            ]);
            if (($this->fullSourceTarget !== null) && ($exception->retryable || in_array($exception->status, [0, 401, 403, 429], true) || $exception->status >= 500)) {
                // Keep this row queued; an API outage must not trigger a check for every remaining place.
                if (! $dryRun) {
                    $this->repository->markBusiness($business['id'], 'pending');
                }
                throw $exception;
            }

            return null;
        }
    }

    private function batchTarget(array $batch, array $item): ResearchTarget
    {
        if (($this->fullSourceTarget !== null)) {
            return $this->fullSourceTarget;
        }
        $submitted = $batch['request']['businesses'][(int) $item['position'] - 1] ?? [];
        $current = $this->repository->business((int) $item['business_id'])['payload'];

        return new ResearchTarget(
            (string) ($item['target_city'] ?? $submitted['address']['city'] ?? $current['address']['city'] ?? ''),
            (string) ($item['target_category'] ?? $submitted['category_key'] ?? $current['category_key'] ?? ''),
        );
    }

    private function resumePendingBatches(string $runId, bool $dryRun, RunReport $report, RunBudget $budget): bool
    {
        foreach ($this->repository->pendingBatches() as $batch) {
            $foreignItems = array_filter($batch['items'], fn (array $item): bool => ! $this->repository->acceptsBusinessSource((int) $item['business_id']));
            if ($foreignItems !== []) {
                $report->error('source_scope_deferred', 'An existing batch contains businesses from another source job; its original request and ID are retained for review.', [
                    'client_import_id' => $batch['client_import_id'],
                    'businesses' => count($batch['items']),
                    'foreign_businesses' => count($foreignItems),
                ]);

                continue;
            }
            $targets = array_map(fn (array $item): ResearchTarget => $this->batchTarget($batch, $item), $batch['items']);
            if (! $budget->fits($targets)) {
                $report->error('budget_deferred', 'Pending batch is larger than the remaining run budget; its unchanged request is deferred.', [
                    'client_import_id' => $batch['client_import_id'],
                    'businesses' => count($batch['items']),
                ]);

                return false;
            }
            foreach ($batch['items'] as $item) {
                $this->seenBusinessIds[(int) $item['business_id']] = true;
            }
            if ($dryRun) {
                foreach ($targets as $index => $target) {
                    $payload = $batch['request']['businesses'][$index];
                    $report->increment(isset($payload['id']) ? 'planned_updates' : 'planned_imports');
                    $budget->record($target);
                    $report->target($target->key(), $target->city, $target->categoryKey, 0, 0, 1);
                }
            } else {
                if (! $this->processBatch($batch, $report, $budget)) {
                    return false;
                }
                $unique = [];
                foreach ($targets as $target) {
                    $unique[$target->key()] = $target;
                    $report->target($target->key(), $target->city, $target->categoryKey, 0);
                }
                foreach ($unique as $target) {
                    $this->repository->markResearchTargetCompleted($target, $runId);
                }
            }
        }

        return true;
    }

    private function sendNewBatch(string $runId, array $items, RunReport $report, RunBudget $budget): bool
    {
        $batch = $this->repository->createBatch($runId, $items);
        $batch['items'] = array_map(
            static fn (array $item, int $index): array => [
                'position' => $index + 1,
                'business_id' => $item['business_id'],
                'payload_hash' => $item['payload_hash'] ?? null,
                'target_city' => $item['target_city'] ?? null,
                'target_category' => $item['target_category'] ?? null,
            ],
            $items,
            array_keys($items)
        );

        return $this->processBatch($batch, $report, $budget);
    }

    private function processBatch(array $batch, RunReport $report, RunBudget $budget): bool
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
            $target = $this->batchTarget($batch, $localItem);
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
                $this->repository->requeueChangedBusiness($businessId, $localItem['payload_hash'] ?? null);
                $budget->record($target);
                $report->target($target->key(), $target->city, $target->categoryKey, 0, 1);
            } elseif ($status === 'updated') {
                $this->repository->markBusiness($businessId, 'updated', $pageId, operation: 'updated');
                $report->increment('updated');
                $this->repository->requeueChangedBusiness($businessId, $localItem['payload_hash'] ?? null);
                $budget->record($target);
                $report->target($target->key(), $target->city, $target->categoryKey, 0, 1);
            } elseif ($status === 'duplicate') {
                $matches = is_array($item['matches'] ?? null) ? $item['matches'] : [];
                $matchId = isset($matches[0]['id']) ? (int) $matches[0]['id'] : null;
                $this->repository->markBusiness($businessId, 'duplicate', $matchId, matches: $matches);
                $this->repository->requeueChangedBusiness($businessId, $localItem['payload_hash'] ?? null);
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
                $this->repository->requeueChangedBusiness($businessId, $localItem['payload_hash'] ?? null);
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
        $sourceMatches = array_values(array_filter($matches, static fn (array $match): bool => in_array('source_id', (array) ($match['matched_on'] ?? []), true)));
        if ($sourceMatches !== []) {
            if (count($sourceMatches) !== 1 || ! isset($sourceMatches[0]['id'])) {
                return [null, 'Several pages are linked to the same source ID.'];
            }

            return $this->resolveLinkedRemote((int) $sourceMatches[0]['id']);
        }
        // The current API supplies branch addresses. Prefer a confirmed location to shared contacts.
        $sameLocation = array_values(array_filter($matches, static fn (array $match): bool => BusinessLocationIdentity::matchStatus($candidate, $match) === 'same'));
        if ($sameLocation !== []) {
            $matches = $sameLocation;
        } else {
            $matches = array_values(array_filter($matches, static fn (array $match): bool => BusinessLocationIdentity::matchStatus($candidate, $match) !== 'different'));
        }
        usort($matches, fn (array $left, array $right): int => $this->matchScore($right) <=> $this->matchScore($left));
        $selected = $matches[0] ?? null;
        if (! is_array($selected)) {
            return [null, 'Duplicate response did not contain a usable match.'];
        }
        if (isset($matches[1]) && $this->matchScore($matches[1]) === $this->matchScore($selected)) {
            return [null, 'Multiple equally strong duplicate matches require manual review.'];
        }

        $filters = [array_filter([
            'name' => $selected['name'] ?? $candidate['name'] ?? null,
            'city' => $candidate['address']['city'] ?? null,
            'per_page' => 100,
        ], static fn ($value) => $value !== null && $value !== '')];
        $matchedOn = (array) ($selected['matched_on'] ?? []);
        if (in_array('contact_email', $matchedOn, true) && isset($candidate['contact_email'])) {
            $filters[] = ['contact_email' => $candidate['contact_email'], 'per_page' => 100];
        }
        if (in_array('phone', $matchedOn, true) && isset($candidate['phone'])) {
            $filters[] = ['phone' => $candidate['phone'], 'per_page' => 100];
        }
        foreach ($filters as $filter) {
            $page = 1;
            do {
                $result = $this->api->searchBusinesses($filter + ['page' => $page]);
                $businesses = (array) ($result['businesses'] ?? []);
                foreach ($businesses as $business) {
                    if (is_array($business) && (int) ($business['id'] ?? 0) === (int) ($selected['id'] ?? 0)) {
                        return [$business, null];
                    }
                }
                $lastPage = (int) ($result['pagination']['last_page'] ?? 1);
                $currentPage = (int) ($result['pagination']['current_page'] ?? $page);
                $page++;
            } while ($businesses !== [] && $currentPage === $page - 1 && $page <= $lastPage);
        }

        return [null, 'Matching page disappeared or could not be returned by the search API.'];
    }

    private function resolveLinkedRemote(int $id): array
    {
        $result = $this->api->searchBusinesses(['id' => $id, 'per_page' => 1]);
        $matches = array_values(array_filter((array) ($result['businesses'] ?? []), static fn (mixed $business): bool => is_array($business) && (int) ($business['id'] ?? 0) === $id));
        if (count($matches) !== 1) {
            return [null, 'The previously linked Sveevee page could not be resolved by ID; no replacement page was created.'];
        }

        return [$matches[0], null];
    }

    private function matchScore(array $match): int
    {
        $signals = (array) ($match['matched_on'] ?? []);
        $weights = ['contact_email' => 100, 'phone' => 90, 'website' => 70, 'address' => 60, 'name' => 40];

        return array_sum(array_map(static fn (string $signal): int => $weights[$signal] ?? 0, $signals));
    }
}
