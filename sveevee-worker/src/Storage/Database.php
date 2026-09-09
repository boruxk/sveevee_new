<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use PDO;
use RuntimeException;

final class Database
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create database directory: {$directory}");
        }

        $this->pdo = new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    private function migrate(): void
    {
        $statements = [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS runs (
    id TEXT PRIMARY KEY,
    command TEXT NOT NULL,
    dry_run INTEGER NOT NULL DEFAULT 0,
    config_hash TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    report_path TEXT,
    report_json TEXT,
    error_message TEXT
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS run_log_outbox (
    run_id TEXT PRIMARY KEY,
    payload_json TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    reported_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS run_log_outbox_pending_idx ON run_log_outbox(reported_at, created_at)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS businesses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payload_json TEXT NOT NULL,
    payload_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    sveevee_page_id INTEGER,
    duplicate_matches_json TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    last_checked_at TEXT,
    imported_at TEXT,
    last_operation TEXT,
    last_error_code TEXT,
    last_error_message TEXT
)
SQL,
            'CREATE INDEX IF NOT EXISTS businesses_status_idx ON businesses(status, id)',
            'CREATE INDEX IF NOT EXISTS businesses_imported_at_idx ON businesses(imported_at)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS business_identity_keys (
    business_id INTEGER NOT NULL,
    key_type TEXT NOT NULL,
    key_value TEXT NOT NULL,
    PRIMARY KEY (business_id, key_type, key_value),
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS business_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    business_id INTEGER NOT NULL,
    adapter TEXT NOT NULL,
    source_name TEXT NOT NULL,
    source_url TEXT,
    source_checked_at TEXT NOT NULL,
    raw_hash TEXT NOT NULL,
    raw_json TEXT NOT NULL,
    UNIQUE (business_id, adapter, source_url),
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS researched_urls (
    adapter TEXT NOT NULL,
    url_hash TEXT NOT NULL,
    source_url TEXT NOT NULL,
    status TEXT NOT NULL,
    business_id INTEGER,
    checked_at TEXT NOT NULL,
    raw_hash TEXT,
    last_error TEXT,
    PRIMARY KEY (adapter, url_hash),
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE SET NULL
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS research_failures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT,
    adapter TEXT NOT NULL,
    source_url TEXT,
    raw_json TEXT,
    error_code TEXT NOT NULL,
    error_message TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE SET NULL
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS research_target_progress (
    target_key TEXT PRIMARY KEY,
    city TEXT NOT NULL,
    category_key TEXT NOT NULL,
    neighborhood TEXT,
    completed_runs INTEGER NOT NULL DEFAULT 0,
    last_run_id TEXT,
    last_completed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (last_run_id) REFERENCES runs(id) ON DELETE SET NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS research_target_progress_completed_idx ON research_target_progress(last_completed_at, target_key)',
            'CREATE TABLE IF NOT EXISTS worker_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS import_batches (
    client_import_id TEXT PRIMARY KEY,
    run_id TEXT,
    payload_hash TEXT NOT NULL,
    request_json TEXT NOT NULL,
    status TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    response_json TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE SET NULL
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS import_batch_items (
    client_import_id TEXT NOT NULL,
    position INTEGER NOT NULL,
    business_id INTEGER NOT NULL,
    payload_hash TEXT,
    target_city TEXT,
    target_category TEXT,
    PRIMARY KEY (client_import_id, position),
    FOREIGN KEY (client_import_id) REFERENCES import_batches(client_import_id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS robots_cache (
    origin TEXT PRIMARY KEY,
    status_code INTEGER,
    body TEXT,
    fetched_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
)
SQL,
        ];

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
        // Existing installations keep pending requests unchanged; null snapshots are replayed conservatively.
        $columns = array_column($this->pdo->query('PRAGMA table_info(import_batch_items)')->fetchAll(), 'name');
        foreach (['payload_hash', 'target_city', 'target_category'] as $column) {
            if (! in_array($column, $columns, true)) {
                $this->pdo->exec('ALTER TABLE import_batch_items ADD COLUMN '.$column.' TEXT');
            }
        }
        $this->migrateLocationIdentityKeys();
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS business_identity_keys_lookup_idx ON business_identity_keys(key_type, key_value, business_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS business_sources_lookup_idx ON business_sources(adapter, source_url, business_id)');
    }

    /** Contact signals can belong to several branches; never renumber businesses or queued batch items. */
    private function migrateLocationIdentityKeys(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(business_identity_keys)')->fetchAll();
        $businessIdColumn = array_values(array_filter($columns, static fn (array $column): bool => $column['name'] === 'business_id'))[0];
        if ((int) $businessIdColumn['pk'] > 0) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(<<<'SQL'
CREATE TABLE business_identity_keys_locations (
    business_id INTEGER NOT NULL,
    key_type TEXT NOT NULL,
    key_value TEXT NOT NULL,
    PRIMARY KEY (business_id, key_type, key_value),
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
)
SQL);
            $this->pdo->exec('INSERT INTO business_identity_keys_locations SELECT business_id, key_type, key_value FROM business_identity_keys');
            $this->pdo->exec('DROP TABLE business_identity_keys');
            $this->pdo->exec('ALTER TABLE business_identity_keys_locations RENAME TO business_identity_keys');
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
