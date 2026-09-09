<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use PDO;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessCandidate;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\SourceRecord;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;
use Sveevee\Worker\Support\Uuid;

final class WorkerRepository
{
    private readonly PDO $pdo;

    public function __construct(
        Database $database,
        private readonly BusinessNormalizer $normalizer,
        private readonly BusinessMerger $merger,
    ) {
        $this->pdo = $database->pdo;
    }

    public function startRun(string $id, string $command, bool $dryRun, string $configHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO runs (id, command, dry_run, config_hash, status, started_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$id, $command, $dryRun ? 1 : 0, $configHash, 'running', Clock::now()]);
    }

    public function finishRun(string $id, string $status, ?string $reportPath, array $report, ?string $error = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE runs SET status = ?, finished_at = ?, report_path = ?, report_json = ?, error_message = ? WHERE id = ?'
        );
        $statement->execute([$status, Clock::now(), $reportPath, Json::encode($report), $error, $id]);
    }

    public function queueRunLog(string $runId, array $report): void
    {
        $now = Clock::now();
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO run_log_outbox (run_id, payload_json, created_at, updated_at)
VALUES (?, ?, ?, ?)
ON CONFLICT(run_id) DO NOTHING
SQL);
        $statement->execute([$runId, Json::encode($report), $now, $now]);
    }

    public function pendingRunLogs(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT run_id, payload_json, attempts FROM run_log_outbox WHERE reported_at IS NULL ORDER BY created_at LIMIT ?'
        );
        $statement->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'run_id' => $row['run_id'],
            'report' => Json::decode($row['payload_json']),
            'attempts' => (int) $row['attempts'],
        ], $statement->fetchAll());
    }

    public function markRunLogAttempt(string $runId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE run_log_outbox SET attempts = attempts + 1, updated_at = ? WHERE run_id = ?'
        );
        $statement->execute([Clock::now(), $runId]);
    }

    public function completeRunLog(string $runId): void
    {
        $now = Clock::now();
        $statement = $this->pdo->prepare(
            'UPDATE run_log_outbox SET reported_at = ?, last_error = NULL, updated_at = ? WHERE run_id = ?'
        );
        $statement->execute([$now, $now, $runId]);
    }

    public function failRunLog(string $runId, string $message): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE run_log_outbox SET last_error = ?, updated_at = ? WHERE run_id = ?'
        );
        $statement->execute([$message, Clock::now(), $runId]);
    }

    public function upsertCandidate(BusinessCandidate $candidate): array
    {
        $keys = $this->normalizer->identityKeys($candidate->data);
        $now = Clock::now();
        $this->pdo->beginTransaction();

        try {
            $ids = [];
            $lookup = $this->pdo->prepare(
                'SELECT business_id FROM business_identity_keys WHERE key_type = ? AND key_value = ?'
            );
            foreach ($keys as $type => $value) {
                $lookup->execute([$type, $value]);
                $id = $lookup->fetchColumn();
                if ($id !== false) {
                    $ids[(int) $id] = true;
                }
            }
            if (count($ids) > 1) {
                throw new IdentityConflictException(array_keys($ids));
            }

            $isNew = $ids === [];
            $changed = false;
            if ($isNew) {
                $payload = $candidate->data;
                $hash = Json::hash($payload);
                $insert = $this->pdo->prepare(
                    'INSERT INTO businesses (payload_json, payload_hash, status, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?)'
                );
                $insert->execute([Json::encode($payload), $hash, 'pending', $now, $now]);
                $businessId = (int) $this->pdo->lastInsertId();
                $changed = true;
            } else {
                $businessId = (int) array_key_first($ids);
                $row = $this->businessRow($businessId);
                $payload = $this->merger->mergeResearchData(Json::decode($row['payload_json']), $candidate->data);
                $hash = Json::hash($payload);
                $changed = ! hash_equals($row['payload_hash'], $hash);
                $status = $row['status'];
                if ($changed && ! in_array($status, ['queued', 'claimed'], true)) {
                    $status = 'pending';
                }
                $update = $this->pdo->prepare(
                    'UPDATE businesses SET payload_json = ?, payload_hash = ?, status = ?, last_seen_at = ?, last_error_code = NULL, last_error_message = NULL WHERE id = ?'
                );
                $update->execute([Json::encode($payload), $hash, $status, $now, $businessId]);
            }

            $insertKey = $this->pdo->prepare(
                'INSERT OR IGNORE INTO business_identity_keys (business_id, key_type, key_value) VALUES (?, ?, ?)'
            );
            foreach ($this->normalizer->identityKeys($payload) as $type => $value) {
                $insertKey->execute([$businessId, $type, $value]);
                if ($insertKey->rowCount() === 0) {
                    $owner = $this->identityOwner($type, $value);
                    if ($owner !== null && $owner !== $businessId) {
                        throw new IdentityConflictException([$businessId, $owner]);
                    }
                }
            }

            foreach ($candidate->sources as $source) {
                $this->saveSource($businessId, $source);
            }
            $this->pdo->commit();

            return ['business_id' => $businessId, 'is_new' => $isNew, 'changed' => $changed];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function shouldProcessUrl(
        string $adapter,
        string $url,
        int $refreshAfterDays,
        ?string $rawHash = null,
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT status, checked_at, raw_hash FROM researched_urls WHERE adapter = ? AND url_hash = ?'
        );
        $statement->execute([$adapter, hash('sha256', $url)]);
        $row = $statement->fetch();
        if ($row === false) {
            return true;
        }
        if ($row['status'] === 'rejected') {
            return $rawHash !== null
                && ! hash_equals((string) ($row['raw_hash'] ?? ''), $rawHash);
        }
        if ($row['status'] !== 'success') {
            return true;
        }
        if ($rawHash !== null && ! hash_equals((string) ($row['raw_hash'] ?? ''), $rawHash)) {
            return true;
        }

        $cutoff = time() - max(1, $refreshAfterDays) * 86400;

        return strtotime((string) $row['checked_at']) < $cutoff;
    }

    public function recordUrlFailure(string $adapter, string $url, string $error): void
    {
        $this->saveResearchedUrl($adapter, $url, 'failed', null, $error, Clock::now(), null);
    }

    public function recordResearchFailure(
        string $runId,
        string $adapter,
        ?string $sourceUrl,
        array $raw,
        string $code,
        string $message,
        bool $retryable = true,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO research_failures (run_id, adapter, source_url, raw_json, error_code, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$runId, $adapter, $sourceUrl, Json::encode($raw), $code, $message, Clock::now()]);
        if ($sourceUrl !== null) {
            $this->saveResearchedUrl(
                $adapter,
                $sourceUrl,
                $retryable ? 'failed' : 'rejected',
                null,
                $message,
                Clock::now(),
                SourceFingerprint::hash($raw),
            );
        }
    }

    public function researchTargetProgress(): array
    {
        $progress = [];
        foreach ($this->pdo->query(
            'SELECT target_key, city, category_key, completed_runs, last_run_id, last_completed_at FROM research_target_progress'
        )->fetchAll() as $row) {
            $progress[$row['target_key']] = [
                'city' => $row['city'],
                'category_key' => $row['category_key'],
                'completed_runs' => (int) $row['completed_runs'],
                'last_run_id' => $row['last_run_id'],
                'last_completed_at' => $row['last_completed_at'],
            ];
        }

        return $progress;
    }

    public function markResearchTargetCompleted(ResearchTarget $target, string $runId): void
    {
        // Distinct visits within one second must keep their order across runs.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO research_target_progress (
    target_key, city, category_key, neighborhood, completed_runs,
    last_run_id, last_completed_at, created_at, updated_at
) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
ON CONFLICT(target_key) DO UPDATE SET
    city = excluded.city,
    category_key = excluded.category_key,
    neighborhood = excluded.neighborhood,
    completed_runs = research_target_progress.completed_runs + 1,
    last_run_id = excluded.last_run_id,
    last_completed_at = excluded.last_completed_at,
    updated_at = excluded.updated_at
SQL);
        $statement->execute([
            $target->key(),
            $target->city,
            $target->categoryKey,
            $target->neighborhood,
            $runId,
            $now,
            $now,
            $now,
        ]);
    }

    public function pendingBusinesses(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM businesses WHERE status = 'pending' ORDER BY id LIMIT ?"
        );
        $statement->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->decodeBusinessRow(...), $statement->fetchAll());
    }

    public function business(int $id): array
    {
        return $this->decodeBusinessRow($this->businessRow($id));
    }

    /** Keyset pagination permits state changes while consuming the pending queue. */
    public function pendingForTarget(ResearchTarget $target): iterable
    {
        $afterId = 0;
        do {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT * FROM businesses
WHERE status = 'pending' AND id > ?
  AND json_extract(payload_json, '$.address.city') = ?
  AND json_extract(payload_json, '$.category_key') = ?
ORDER BY id LIMIT 100
SQL);
            $statement->execute([$afterId, $target->city, $target->categoryKey]);
            $rows = $statement->fetchAll();
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                yield $this->decodeBusinessRow($row);
            }
        } while (count($rows) === 100);
    }

    public function pendingTargets(): array
    {
        $rows = $this->pdo->query(<<<'SQL'
SELECT json_extract(payload_json, '$.address.city') AS city,
       json_extract(payload_json, '$.category_key') AS category
FROM businesses WHERE status IN ('pending', 'queued')
GROUP BY city, category ORDER BY MIN(id)
SQL)->fetchAll();

        return array_map(static fn (array $row): ResearchTarget => new ResearchTarget(
            (string) $row['city'], (string) $row['category'],
        ), $rows);
    }

    public function markBusiness(
        int $id,
        string $status,
        ?int $pageId = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?array $matches = null,
        ?string $operation = null,
    ): void {
        $importedAt = in_array($status, ['imported', 'updated'], true) ? Clock::now() : null;
        $statement = $this->pdo->prepare(
            'UPDATE businesses SET status = ?, sveevee_page_id = COALESCE(?, sveevee_page_id), duplicate_matches_json = ?, attempts = attempts + 1, last_checked_at = ?, imported_at = COALESCE(?, imported_at), last_operation = COALESCE(?, last_operation), last_error_code = ?, last_error_message = ? WHERE id = ?'
        );
        $statement->execute([
            $status,
            $pageId,
            $matches === null ? null : Json::encode($matches),
            Clock::now(),
            $importedAt,
            $operation,
            $errorCode,
            $errorMessage,
            $id,
        ]);
    }

    public function createBatch(string $runId, array $items): array
    {
        if ($items === [] || count($items) > 100) {
            throw new RuntimeException('A worker batch must contain between 1 and 100 businesses.');
        }

        foreach ($items as &$item) {
            $business = $this->business((int) $item['business_id']);
            $item['payload_hash'] ??= $business['payload_hash'];
            $item['target_city'] ??= $business['payload']['address']['city'];
            $item['target_category'] ??= $business['payload']['category_key'];
        }
        unset($item);
        $batchId = Uuid::v4();
        $request = [
            'client_import_id' => $batchId,
            'businesses' => array_values(array_map(static fn (array $item): array => $item['payload'], $items)),
        ];
        $now = Clock::now();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO import_batches (client_import_id, run_id, payload_hash, request_json, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$batchId, $runId, Json::hash($request['businesses']), Json::encode($request), 'pending', $now, $now]);
            $itemStatement = $this->pdo->prepare(
                'INSERT INTO import_batch_items (client_import_id, position, business_id, payload_hash, target_city, target_category) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $queue = $this->pdo->prepare("UPDATE businesses SET status = 'queued' WHERE id = ? AND status = 'pending'");
            foreach (array_values($items) as $index => $item) {
                $position = $index + 1;
                $itemStatement->execute([$batchId, $position, $item['business_id'], $item['payload_hash'], $item['target_city'], $item['target_category']]);
                $queue->execute([$item['business_id']]);
                if ($queue->rowCount() !== 1) {
                    throw new RuntimeException('A business changed state while the batch was being prepared.');
                }
            }
            $this->pdo->commit();

            return ['client_import_id' => $batchId, 'request' => $request, 'items' => $items];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function pendingBatches(): array
    {
        $rows = $this->pdo->query(
            "SELECT * FROM import_batches WHERE status = 'pending' ORDER BY created_at"
        )->fetchAll();

        return array_map(function (array $row): array {
            $items = $this->pdo->prepare(
                'SELECT position, business_id, payload_hash, target_city, target_category FROM import_batch_items WHERE client_import_id = ? ORDER BY position'
            );
            $items->execute([$row['client_import_id']]);
            $row['request'] = Json::decode($row['request_json']);
            $row['items'] = $items->fetchAll();

            return $row;
        }, $rows);
    }

    public function requeueChangedBusiness(int $id, ?string $submittedHash): void
    {
        $statement = $this->pdo->prepare("UPDATE businesses SET status = 'pending' WHERE id = ? AND status IN ('imported', 'updated', 'duplicate', 'invalid', 'not_found', 'failed') AND (? IS NULL OR payload_hash != ?)");
        $statement->execute([$id, $submittedHash, $submittedHash]);
    }

    public function markBatchAttempt(string $batchId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE import_batches SET attempts = attempts + 1, updated_at = ? WHERE client_import_id = ?'
        );
        $statement->execute([Clock::now(), $batchId]);
    }

    public function completeBatch(string $batchId, array $response): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE import_batches SET status = 'completed', response_json = ?, last_error = NULL, updated_at = ? WHERE client_import_id = ?"
        );
        $statement->execute([Json::encode($response), Clock::now(), $batchId]);
    }

    public function failBatch(string $batchId, string $message, bool $retryable): void
    {
        $status = $retryable ? 'pending' : 'failed';
        $statement = $this->pdo->prepare(
            'UPDATE import_batches SET status = ?, last_error = ?, updated_at = ? WHERE client_import_id = ?'
        );
        $statement->execute([$status, $message, Clock::now(), $batchId]);
    }

    public function resetFailed(int $limit): int
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                "SELECT id FROM businesses WHERE status IN ('failed', 'not_found') ORDER BY id LIMIT ?"
            );
            $select->bindValue(1, max(1, $limit), PDO::PARAM_INT);
            $select->execute();
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE businesses SET status = 'pending', last_error_code = NULL, last_error_message = NULL WHERE id IN ({$placeholders})"
                );
                $update->execute($ids);
            }
            $this->pdo->commit();

            return count($ids);
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function statusSummary(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS total FROM businesses GROUP BY status')->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $researchUrls = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS total FROM researched_urls GROUP BY status')->fetchAll() as $row) {
            $researchUrls[$row['status']] = (int) $row['total'];
        }
        $lastRun = $this->pdo->query(
            'SELECT id, command, dry_run, status, started_at, finished_at, report_path, error_message FROM runs ORDER BY started_at DESC LIMIT 1'
        )->fetch() ?: null;
        $recentFailures = array_map(function (array $row): array {
            $payload = Json::decode($row['payload_json']);

            return [
                'id' => (int) $row['id'],
                'name' => $payload['name'] ?? null,
                'status' => $row['status'],
                'error_code' => $row['last_error_code'],
                'error_message' => $row['last_error_message'],
            ];
        }, $this->pdo->query(
            "SELECT id, payload_json, status, last_error_code, last_error_message FROM businesses WHERE status IN ('failed', 'invalid', 'not_found') ORDER BY last_checked_at DESC, id DESC LIMIT 20"
        )->fetchAll());
        $pendingBatches = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM import_batches WHERE status = 'pending'"
        )->fetchColumn();
        $pendingRunLogs = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM run_log_outbox WHERE reported_at IS NULL'
        )->fetchColumn();

        return [
            'businesses' => $counts,
            'total' => array_sum($counts),
            'research_urls' => $researchUrls,
            'pending_batches' => $pendingBatches,
            'pending_admin_logs' => $pendingRunLogs,
            'last_run' => $lastRun,
            'recent_failures' => $recentFailures,
        ];
    }

    public function robotsCache(string $origin): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM robots_cache WHERE origin = ? AND expires_at > ?');
        $statement->execute([$origin, Clock::now()]);

        return $statement->fetch() ?: null;
    }

    public function cacheRobots(string $origin, int $status, ?string $body, string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO robots_cache (origin, status_code, body, fetched_at, expires_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(origin) DO UPDATE SET status_code = excluded.status_code, body = excluded.body, fetched_at = excluded.fetched_at, expires_at = excluded.expires_at'
        );
        $statement->execute([$origin, $status, $body, Clock::now(), $expiresAt]);
    }

    private function businessRow(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM businesses WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new RuntimeException("Business {$id} no longer exists.");
        }

        return $row;
    }

    private function decodeBusinessRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['payload'] = Json::decode($row['payload_json']);
        $row['duplicate_matches'] = $row['duplicate_matches_json'] ? Json::decode($row['duplicate_matches_json']) : null;

        return $row;
    }

    private function identityOwner(string $type, string $value): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT business_id FROM business_identity_keys WHERE key_type = ? AND key_value = ?'
        );
        $statement->execute([$type, $value]);
        $owner = $statement->fetchColumn();

        return $owner === false ? null : (int) $owner;
    }

    private function saveSource(int $businessId, SourceRecord $source): void
    {
        $sourceUrl = $source->url ?? '';
        $statement = $this->pdo->prepare(
            'INSERT INTO business_sources (business_id, adapter, source_name, source_url, source_checked_at, raw_hash, raw_json) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(business_id, adapter, source_url) DO UPDATE SET source_name = excluded.source_name, source_checked_at = excluded.source_checked_at, raw_hash = excluded.raw_hash, raw_json = excluded.raw_json'
        );
        $statement->execute([
            $businessId,
            $source->adapter,
            $source->name,
            $sourceUrl,
            $source->checkedAt,
            SourceFingerprint::hash($source->raw),
            Json::encode($source->raw),
        ]);
        if ($source->url !== null) {
            $this->saveResearchedUrl(
                $source->adapter,
                $source->url,
                'success',
                $businessId,
                null,
                $source->checkedAt,
                SourceFingerprint::hash($source->raw),
            );
        }
    }

    private function saveResearchedUrl(
        string $adapter,
        string $url,
        string $status,
        ?int $businessId,
        ?string $error,
        string $checkedAt,
        ?string $rawHash,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO researched_urls (adapter, url_hash, source_url, status, business_id, checked_at, raw_hash, last_error) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(adapter, url_hash) DO UPDATE SET source_url = excluded.source_url, status = excluded.status, business_id = excluded.business_id, checked_at = excluded.checked_at, raw_hash = excluded.raw_hash, last_error = excluded.last_error'
        );
        $statement->execute([$adapter, hash('sha256', $url), $url, $status, $businessId, $checkedAt, $rawHash, $error]);
    }
}
