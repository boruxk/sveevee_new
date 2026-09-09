<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use PDO;
use RuntimeException;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;

/** A bounded, durable page queue for sequential source scans. Legacy caches remain separate. */
final class SourceRecordCursor
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS source_record_scans (
    scan_key TEXT PRIMARY KEY, adapter TEXT NOT NULL, scopes_json TEXT NOT NULL,
    scope_index INTEGER NOT NULL DEFAULT 0, cycle INTEGER NOT NULL DEFAULT 1,
    completed_at TEXT, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS source_record_scopes (
    scan_key TEXT NOT NULL, scope_key TEXT NOT NULL, next_offset INTEGER NOT NULL DEFAULT 0,
    consumed_offset INTEGER NOT NULL DEFAULT 0, expected_total INTEGER,
    complete INTEGER NOT NULL DEFAULT 0, last_page_ids TEXT,
    PRIMARY KEY (scan_key, scope_key),
    FOREIGN KEY (scan_key) REFERENCES source_record_scans(scan_key) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS source_record_queue (
    scan_key TEXT NOT NULL, scope_key TEXT NOT NULL, position INTEGER NOT NULL,
    record_id TEXT NOT NULL, record_json TEXT NOT NULL, checked_at TEXT NOT NULL,
    PRIMARY KEY (scan_key, scope_key, position),
    FOREIGN KEY (scan_key, scope_key) REFERENCES source_record_scopes(scan_key, scope_key) ON DELETE CASCADE
);
SQL);
    }

    public function open(string $scanKey, string $adapter, array $scopeKeys, int $refreshSeconds): void
    {
        if ($scanKey === '' || $adapter === '' || $scopeKeys === [] || ! array_is_list($scopeKeys)
            || count(array_unique($scopeKeys)) !== count($scopeKeys)) {
            throw new RuntimeException('A sequential scan requires unique ordered resource scopes.');
        }
        foreach ($scopeKeys as $scope) {
            if (! is_string($scope) || $scope === '') {
                throw new RuntimeException('A sequential scan scope must be a nonempty string.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM source_record_scans WHERE scan_key = ?');
            $query->execute([$scanKey]);
            $scan = $query->fetch(PDO::FETCH_ASSOC);
            if ($scan === false) {
                $this->pdo->prepare('INSERT INTO source_record_scans (scan_key, adapter, scopes_json, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$scanKey, $adapter, Json::encode($scopeKeys), Clock::now()]);
                $insert = $this->pdo->prepare('INSERT INTO source_record_scopes (scan_key, scope_key) VALUES (?, ?)');
                foreach ($scopeKeys as $scope) {
                    $insert->execute([$scanKey, $scope]);
                }
            } elseif ($scan['adapter'] !== $adapter || Json::decode($scan['scopes_json']) !== $scopeKeys) {
                throw new RuntimeException('A sequential scan key was reused for different resources.');
            } elseif ($scan['completed_at'] !== null && strtotime($scan['completed_at']) <= time() - max(0, $refreshSeconds)) {
                $this->pdo->prepare('DELETE FROM source_record_queue WHERE scan_key = ?')->execute([$scanKey]);
                $this->pdo->prepare('UPDATE source_record_scopes SET next_offset = 0, consumed_offset = 0, expected_total = NULL, complete = 0, last_page_ids = NULL WHERE scan_key = ?')->execute([$scanKey]);
                $this->pdo->prepare('UPDATE source_record_scans SET scope_index = 0, cycle = cycle + 1, completed_at = NULL WHERE scan_key = ?')->execute([$scanKey]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Advance only after the complete resource is durably consumed. */
    public function currentScope(string $scanKey): ?string
    {
        $query = $this->pdo->prepare('SELECT * FROM source_record_scans WHERE scan_key = ?');
        $query->execute([$scanKey]);
        $scan = $query->fetch(PDO::FETCH_ASSOC);
        if ($scan === false) {
            throw new RuntimeException('Sequential source scan is missing.');
        }
        $scopes = Json::decode($scan['scopes_json']);
        $index = (int) $scan['scope_index'];
        while (isset($scopes[$index])) {
            $state = $this->state($scanKey, $scopes[$index]);
            if (! (bool) $state['complete'] || (int) $state['consumed_offset'] < (int) $state['next_offset']) {
                return $scopes[$index];
            }
            $index++;
            $this->pdo->prepare('UPDATE source_record_scans SET scope_index = ? WHERE scan_key = ?')->execute([$index, $scanKey]);
        }
        $this->pdo->prepare('UPDATE source_record_scans SET completed_at = COALESCE(completed_at, ?) WHERE scan_key = ?')->execute([Clock::now(), $scanKey]);

        return null;
    }

    public function state(string $scanKey, string $scopeKey): array
    {
        $query = $this->pdo->prepare('SELECT scope.*, scan.cycle FROM source_record_scopes scope JOIN source_record_scans scan USING(scan_key) WHERE scope.scan_key = ? AND scope.scope_key = ?');
        $query->execute([$scanKey, $scopeKey]);
        $state = $query->fetch(PDO::FETCH_ASSOC);
        if ($state === false) {
            throw new RuntimeException('Sequential source scope is missing.');
        }

        return $state;
    }

    /** Persist an ArcGIS count before a request budget can end. A mutable total never resets progress. */
    public function setTotal(string $scanKey, string $scopeKey, int $total): void
    {
        if ($total < 0) {
            throw new RuntimeException('Source total cannot be negative.');
        }
        $state = $this->state($scanKey, $scopeKey);
        $this->pdo->prepare('UPDATE source_record_scopes SET expected_total = ?, complete = ? WHERE scan_key = ? AND scope_key = ?')
            ->execute([$total, (int) $state['next_offset'] >= $total ? 1 : 0, $scanKey, $scopeKey]);
    }

    /** @return array<array{position: int, record_id: string, record: array, checked_at: string}> */
    public function pending(string $scanKey, string $scopeKey): array
    {
        $query = $this->pdo->prepare('SELECT position, record_id, record_json, checked_at FROM source_record_queue WHERE scan_key = ? AND scope_key = ? ORDER BY position');
        $query->execute([$scanKey, $scopeKey]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 10) {
            throw new RuntimeException('Sequential source queue exceeds its ten-row bound.');
        }

        return array_map(static fn (array $row): array => [
            'position' => (int) $row['position'], 'record_id' => $row['record_id'],
            'record' => Json::decode($row['record_json']), 'checked_at' => $row['checked_at'],
        ], $rows);
    }

    /** Save one complete validated page before exposing any of its rows. */
    public function append(string $scanKey, string $scopeKey, int $offset, int $total, array $rows, string $checkedAt, int $maxBytes): void
    {
        if ($offset < 0 || $total < 0 || count($rows) > 10 || ($rows === [] && $offset < $total)
            || ($rows !== [] && $offset + count($rows) > $total)) {
            throw new RuntimeException('Source returned an inconsistent sequential page.');
        }
        $this->pdo->beginTransaction();
        try {
            $state = $this->state($scanKey, $scopeKey);
            if ((int) $state['next_offset'] !== $offset || (int) $state['consumed_offset'] !== $offset) {
                throw new RuntimeException('The previous source page must be acknowledged before fetching another.');
            }
            $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
            if (count(array_unique($ids)) !== count($ids) || in_array('', $ids, true)
                || ($rows !== [] && $state['last_page_ids'] === Json::encode($ids))) {
                throw new RuntimeException('Source repeated a row or page during sequential pagination.');
            }
            $insert = $this->pdo->prepare('INSERT INTO source_record_queue (scan_key, scope_key, position, record_id, record_json, checked_at) VALUES (?, ?, ?, ?, ?, ?)');
            $bytes = 0;
            foreach ($rows as $index => $row) {
                if (! is_array($row['record'] ?? null)) {
                    throw new RuntimeException('Sequential source rows must retain their original objects.');
                }
                $json = Json::encode($row['record']);
                $bytes += strlen($json);
                if ($bytes > max(1, $maxBytes)) {
                    throw new RuntimeException('Source page exceeds max_cache_bytes.');
                }
                $insert->execute([$scanKey, $scopeKey, $offset + $index + 1, $ids[$index], $json, $checkedAt]);
            }
            $next = $offset + count($rows);
            $this->pdo->prepare('UPDATE source_record_scopes SET next_offset = ?, expected_total = ?, complete = ?, last_page_ids = ? WHERE scan_key = ? AND scope_key = ?')
                ->execute([$next, $total, $next >= $total ? 1 : 0, Json::encode($ids), $scanKey, $scopeKey]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /** The caller must first persist the candidate, duplicate decision or rejection. */
    public function acknowledge(string $scanKey, string $scopeKey, int $position, int $cycle): void
    {
        $this->pdo->beginTransaction();
        try {
            $state = $this->state($scanKey, $scopeKey);
            if ((int) $state['cycle'] !== $cycle) {
                throw new RuntimeException('Cannot acknowledge a row from a different source scan cycle.');
            }
            if ($position <= (int) $state['consumed_offset']) {
                $this->pdo->commit();

                return;
            }
            if ($position !== (int) $state['consumed_offset'] + 1 || $position > (int) $state['next_offset']) {
                throw new RuntimeException('Source rows must be acknowledged in order.');
            }
            $delete = $this->pdo->prepare('DELETE FROM source_record_queue WHERE scan_key = ? AND scope_key = ? AND position = ?');
            $delete->execute([$scanKey, $scopeKey, $position]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The source row being acknowledged is missing.');
            }
            $this->pdo->prepare('UPDATE source_record_scopes SET consumed_offset = ? WHERE scan_key = ? AND scope_key = ?')->execute([$position, $scanKey, $scopeKey]);
            $this->pdo->commit();
            $this->currentScope($scanKey);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
