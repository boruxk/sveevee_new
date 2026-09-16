<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;

/** Consumes trusted, committed backend decisions; never creates or retries an import. */
final class FoursquareReviewReconciliation
{
    public function __construct(private readonly PDO $database) {}

    public function run(array $manifest, bool $apply = false, int $limit = 9000): array
    {
        $this->validate($manifest, $limit);
        $summary = ['provider' => 'foursquare_places', 'apply' => $apply, 'limit' => $limit,
            'examined' => 0, 'would_reconcile' => 0, 'reconciled' => 0, 'already_reconciled' => 0,
            'protected' => 0, 'waiting' => 0, 'blocked' => 0, 'beyond_limit' => 0, 'reasons' => []];
        if ($apply) {
            $this->database->exec('CREATE TABLE IF NOT EXISTS foursquare_review_reconciliations (
                source_id TEXT PRIMARY KEY,review_id INTEGER NOT NULL,page_id INTEGER NOT NULL,business_id INTEGER NOT NULL,
                decision_hash TEXT NOT NULL,decision_json TEXT NOT NULL,payload_hash TEXT NOT NULL,
                before_json TEXT NOT NULL,reconciled_at TEXT NOT NULL
            )');
            $this->database->exec('CREATE INDEX IF NOT EXISTS foursquare_review_pending_idx ON import_batch_items(business_id,client_import_id)');
        }
        $hasJournal = $this->database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='foursquare_review_reconciliations'")->fetchColumn() > 0;
        $pending = array_fill_keys($this->database->query("SELECT DISTINCT i.business_id FROM import_batches b JOIN import_batch_items i ON i.client_import_id=b.client_import_id WHERE b.status='pending'")->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($manifest['decisions'] as $decision) {
            $summary['examined']++;
            $query = $this->database->prepare("SELECT DISTINCT b.* FROM business_sources s JOIN businesses b ON b.id=s.business_id WHERE s.adapter='foursquare_places' AND s.source_url=? LIMIT 2");
            $query->execute([$decision['source_url']]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                $this->count($summary, 'blocked', count($rows) === 0 ? 'source_not_found' : 'ambiguous_local_source');

                continue;
            }
            $row = $rows[0];
            $payload = Json::decode($row['payload_json']);
            $source = $payload['source'] ?? [];
            if (($source['provider'] ?? null) !== 'foursquare_places'
                || ($source['id'] ?? null) !== $decision['source_id']
                || ($source['url'] ?? null) !== $decision['source_url']
                || ! is_array($source['metadata'] ?? null)
                || ! hash_equals($row['payload_hash'], Json::hash($payload))
                || ! hash_equals($decision['source_metadata_hash'], Json::hash($source['metadata']))) {
                $this->count($summary, 'blocked', 'source_evidence_changed');

                continue;
            }
            if (in_array($row['status'], ['closed', 'claimed'], true)) {
                $this->count($summary, 'protected', $row['status']);

                continue;
            }
            if (isset($pending[(int) $row['id']]) || in_array($row['status'], ['pending', 'queued'], true)) {
                $this->count($summary, 'waiting', 'business_already_pending');

                continue;
            }
            $receipt = null;
            if ($hasJournal) {
                $query = $this->database->prepare('SELECT * FROM foursquare_review_reconciliations WHERE source_id=?');
                $query->execute([$decision['source_id']]);
                $receipt = $query->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $decisionHash = Json::hash($decision);
            if ($receipt !== null) {
                if (hash_equals($receipt['decision_hash'], $decisionHash)
                    && (int) $receipt['business_id'] === (int) $row['id']
                    && (int) $row['sveevee_page_id'] === $decision['page_id']
                    && in_array($row['status'], ['imported', 'updated'], true)) {
                    $summary['already_reconciled']++;
                } else {
                    $this->count($summary, 'blocked', 'existing_reconciliation_conflict');
                }

                continue;
            }
            if ($row['status'] !== 'review' || $row['last_error_code'] !== 'review_required') {
                $this->count($summary, 'blocked', 'not_an_unresolved_review');

                continue;
            }
            if (($row['sveevee_page_id'] !== null && (int) $row['sveevee_page_id'] !== $decision['page_id'])
                || (isset($payload['id']) && (int) $payload['id'] !== $decision['page_id'])) {
                $this->count($summary, 'blocked', 'existing_page_association_conflict');

                continue;
            }
            if ($summary['would_reconcile'] >= $limit) {
                $summary['beyond_limit']++;

                continue;
            }
            $summary['would_reconcile']++;
            if ($apply) {
                $this->apply($row, $decision, $decisionHash);
                $summary['reconciled']++;
            }
        }

        return $summary;
    }

    private function apply(array $row, array $decision, string $decisionHash): void
    {
        $this->database->beginTransaction();
        try {
            $now = Clock::now();
            $query = $this->database->prepare("UPDATE businesses SET status='imported',sveevee_page_id=?,last_operation='created',
                imported_at=COALESCE(imported_at,?),last_checked_at=?,last_error_code=NULL,last_error_message=NULL
                WHERE id=? AND status='review' AND last_error_code='review_required' AND payload_hash=? AND attempts=? AND sveevee_page_id IS ?
                AND NOT EXISTS (SELECT 1 FROM import_batch_items i JOIN import_batches b ON b.client_import_id=i.client_import_id WHERE i.business_id=businesses.id AND b.status='pending')");
            $query->execute([$decision['page_id'], $decision['decided_at'], $now, $row['id'], $row['payload_hash'], $row['attempts'], $row['sveevee_page_id']]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('The local review changed during reconciliation. No decision was applied to it.');
            }
            $this->database->prepare('INSERT INTO foursquare_review_reconciliations(source_id,review_id,page_id,business_id,decision_hash,decision_json,payload_hash,before_json,reconciled_at) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$decision['source_id'], $decision['review_id'], $decision['page_id'], $row['id'], $decisionHash,
                    Json::encode($decision), $row['payload_hash'], Json::encode($row), $now]);
            $this->database->commit();
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    private function validate(array $manifest, int $limit): void
    {
        if ($limit < 1 || $limit > 9000 || ($manifest['version'] ?? null) !== 1
            || ($manifest['provider'] ?? null) !== 'foursquare_places'
            || ! is_array($manifest['decisions'] ?? null) || ! array_is_list($manifest['decisions'])
            || count($manifest['decisions']) > 9000) {
            throw new RuntimeException('Use a version 1 Foursquare decision manifest with at most 9000 decisions and limit 1..9000.');
        }
        $seen = [];
        $reviews = [];
        foreach ($manifest['decisions'] as $decision) {
            if (! is_array($decision) || ! is_string($decision['source_id'] ?? null)
                || preg_match('/^[0-9a-f]{24}$/D', $decision['source_id']) !== 1
                || ($decision['source_url'] ?? null) !== 'https://foursquare.com/placemakers/review-place/'.$decision['source_id']
                || ! is_int($decision['page_id'] ?? null) || $decision['page_id'] < 1
                || ! is_int($decision['review_id'] ?? null) || $decision['review_id'] < 1
                || ($decision['operation'] ?? null) !== 'created'
                || ! is_string($decision['source_metadata_hash'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/D', $decision['source_metadata_hash']) !== 1
                || ! is_string($decision['decided_at'] ?? null)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $decision['decided_at']) !== 1
                || isset($seen[$decision['source_id']]) || isset($reviews[$decision['review_id']])) {
                throw new RuntimeException('The manifest contains an invalid, duplicate or unapproved decision.');
            }
            $time = new DateTimeImmutable($decision['decided_at']);
            if (DateTimeImmutable::getLastErrors() !== false || $time->getTimestamp() > time() + 60) {
                throw new RuntimeException('The manifest contains an invalid decision timestamp.');
            }
            $seen[$decision['source_id']] = true;
            $reviews[$decision['review_id']] = true;
        }
    }

    private function count(array &$summary, string $metric, string $reason): void
    {
        $summary[$metric]++;
        $summary['reasons'][$reason] = ($summary['reasons'][$reason] ?? 0) + 1;
    }
}
