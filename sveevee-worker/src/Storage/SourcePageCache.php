<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use PDO;
use RuntimeException;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;

/** Validated source pages and their next offset survive an interrupted research run. */
final class SourcePageCache
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS source_page_scopes (
    cache_key TEXT PRIMARY KEY,
    adapter TEXT NOT NULL,
    expected_total INTEGER,
    next_offset INTEGER NOT NULL DEFAULT 0,
    complete INTEGER NOT NULL DEFAULT 0,
    byte_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT
)
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS source_page_rows (
    cache_key TEXT NOT NULL,
    row_id TEXT NOT NULL,
    position INTEGER NOT NULL,
    record_json TEXT,
    checked_at TEXT NOT NULL,
    PRIMARY KEY (cache_key, row_id),
    UNIQUE (cache_key, position),
    FOREIGN KEY (cache_key) REFERENCES source_page_scopes(cache_key) ON DELETE CASCADE
)
SQL);
    }

    /** Call once per scope per adapter instance, so categories reuse the same snapshot. */
    public function open(string $key, string $adapter, int $refreshSeconds): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM source_page_scopes WHERE cache_key = ?');
        $statement->execute([$key]);
        $state = $statement->fetch();
        if ($state !== false && (bool) $state['complete']
            && strtotime((string) $state['completed_at']) <= time() - max(0, $refreshSeconds)) {
            $this->discard($key);
            $state = false;
        }
        if ($state === false) {
            $now = Clock::now();
            $this->pdo->prepare('INSERT INTO source_page_scopes (cache_key, adapter, created_at, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$key, $adapter, $now, $now]);
        }

        return $this->state($key);
    }

    public function state(string $key): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM source_page_scopes WHERE cache_key = ?');
        $statement->execute([$key]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new RuntimeException('Persistent source pagination state is missing.');
        }

        return $state;
    }

    /** A separate ArcGIS count request must also survive a budget ending before the first page. */
    public function setTotal(string $key, int $total): void
    {
        $state = $this->state($key);
        if ($total < 0 || ($state['expected_total'] !== null && (int) $state['expected_total'] !== $total)) {
            throw new RuntimeException('Source record count changed during cached pagination.');
        }
        $now = Clock::now();
        $this->pdo->prepare('UPDATE source_page_scopes SET expected_total = ?, complete = ?, updated_at = ?, completed_at = ? WHERE cache_key = ?')
            ->execute([$total, $total === 0 ? 1 : 0, $now, $total === 0 ? $now : null, $key]);
    }

    /** @return iterable<array{record: array, checked_at: string}> */
    public function records(string $key): iterable
    {
        $statement = $this->pdo->prepare('SELECT record_json, checked_at FROM source_page_rows WHERE cache_key = ? AND record_json IS NOT NULL ORDER BY position');
        $statement->execute([$key]);
        try {
            while (($row = $statement->fetch()) !== false) {
                yield ['record' => Json::decode($row['record_json']), 'checked_at' => $row['checked_at']];
            }
        } finally {
            $statement->closeCursor();
        }
    }

    /** All IDs are retained to detect repeated pages; ineligible national-register rows need no large JSON body. */
    public function append(string $key, int $offset, int $total, array $rows, string $checkedAt, int $maxBytes): array
    {
        $this->pdo->beginTransaction();
        try {
            $state = $this->state($key);
            if ((int) $state['next_offset'] !== $offset || ($state['expected_total'] !== null && (int) $state['expected_total'] !== $total)
                || $offset + count($rows) > $total || (count($rows) === 0 && $offset < $total)) {
                throw new RuntimeException('Source pagination changed before its page could be committed.');
            }
            $insert = $this->pdo->prepare('INSERT INTO source_page_rows (cache_key, row_id, position, record_json, checked_at) VALUES (?, ?, ?, ?, ?)');
            $exists = $this->pdo->prepare('SELECT 1 FROM source_page_rows WHERE cache_key = ? AND row_id = ?');
            $bytes = (int) $state['byte_count'];
            foreach ($rows as $index => $row) {
                $exists->execute([$key, $row['id']]);
                if ($exists->fetchColumn() !== false) {
                    throw new RuntimeException('Source repeated a row during cached pagination.');
                }
                $json = $row['record'] === null ? null : Json::encode($row['record']);
                $bytes += $json === null ? 0 : strlen($json);
                if ($bytes > $maxBytes) {
                    throw new RuntimeException('Source scope exceeded max_cache_bytes.');
                }
                $insert->execute([$key, $row['id'], $offset + $index, $json, $checkedAt]);
            }
            $next = $offset + count($rows);
            $complete = $next === $total;
            $this->pdo->prepare('UPDATE source_page_scopes SET expected_total = ?, next_offset = ?, complete = ?, byte_count = ?, updated_at = ?, completed_at = ? WHERE cache_key = ?')
                ->execute([$total, $next, $complete ? 1 : 0, $bytes, $checkedAt, $complete ? $checkedAt : null, $key]);
            $this->pdo->commit();

            return $this->state($key);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function discard(string $key): void
    {
        $this->pdo->prepare('DELETE FROM source_page_scopes WHERE cache_key = ?')->execute([$key]);
    }
}
