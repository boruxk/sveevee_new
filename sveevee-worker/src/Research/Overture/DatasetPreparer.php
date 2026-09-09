<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Overture;

use PDO;
use RuntimeException;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;

/** Build a separate snapshot, then publish one complete SQLite file with an atomic rename. */
final class DatasetPreparer
{
    private const MAX_EXPORT_BYTES = 268435456;

    private const MAX_EXPORT_ROWS = 300000;

    public function __construct(private readonly PlaceMapper $mapper) {}

    public function prepare(string $duckdb, array $files, string $release, string $destination, float $minConfidence = 0.75): array
    {
        ReleaseCatalog::validateRelease($release);
        if ($files === [] || $minConfidence < 0 || $minConfidence > 1 || ! is_finite($minConfidence)) {
            throw new RuntimeException('Overture preparation requires source files and a valid confidence threshold.');
        }
        $this->directory(dirname($destination));
        $lock = new ProcessLock($destination.'.prepare.lock');
        $lock->acquire();
        $stage = $destination.'.stage-'.bin2hex(random_bytes(8));
        $jsonl = $stage.'.jsonl';
        $countFile = $stage.'.count.json';
        try {
            $count = $this->export($duckdb, $files, $jsonl, $countFile, $minConfidence);

            return $this->importJsonl($jsonl, $release, $destination, $count, $files, $minConfidence, $stage);
        } finally {
            foreach ([$jsonl, $countFile, $stage] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** Also used with deterministic fixtures. Caller must serialize writes to destination. */
    public function importJsonl(string $jsonl, string $release, string $destination, int $expectedRows, array $files = [], float $minConfidence = 0.75, ?string $stage = null): array
    {
        ReleaseCatalog::validateRelease($release);
        if ($minConfidence < 0 || $minConfidence > 1 || ! is_finite($minConfidence)) {
            throw new RuntimeException('Overture min_confidence must be between 0 and 1.');
        }
        if ($expectedRows < 1 || $expectedRows > self::MAX_EXPORT_ROWS || ! is_file($jsonl) || filesize($jsonl) > self::MAX_EXPORT_BYTES) {
            throw new RuntimeException('Empty, missing or oversized Overture export; existing snapshot was retained.');
        }
        $this->directory(dirname($destination));
        $stage ??= $destination.'.stage-'.bin2hex(random_bytes(8));
        if (file_exists($stage)) {
            throw new RuntimeException('Overture SQLite staging file already exists.');
        }
        $stream = fopen($jsonl, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read the Overture JSONL export.');
        }
        $database = null;
        $insert = null;
        $metadataInsert = null;
        $checkedAt = Clock::now();
        $counts = ['read' => 0, 'accepted' => 0, 'phone' => 0, 'email' => 0, 'website' => 0, 'socials' => 0, 'skipped' => [], 'cities' => [], 'categories' => []];
        try {
            $database = new PDO('sqlite:'.$stage, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database->exec('PRAGMA journal_mode = DELETE; PRAGMA synchronous = FULL');
            $database->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $database->exec('CREATE TABLE places (
                id TEXT PRIMARY KEY, name TEXT NOT NULL, category_key TEXT NOT NULL, city TEXT NOT NULL,
                street TEXT NOT NULL, phone TEXT, email TEXT, website TEXT, social_links TEXT NOT NULL,
                confidence REAL NOT NULL, release TEXT NOT NULL, source_url TEXT NOT NULL,
                source_name TEXT NOT NULL, source_checked_at TEXT NOT NULL, source_metadata TEXT NOT NULL
            )');
            $database->exec('CREATE INDEX places_city_category_id ON places (city, category_key, id)');
            $database->beginTransaction();
            $insert = $database->prepare('INSERT INTO places VALUES (:id, :name, :category_key, :city, :street, :phone, :email, :website, :social_links, :confidence, :release, :source_url, :source_name, :source_checked_at, :source_metadata)');
            while (($line = fgets($stream, 2097153)) !== false) {
                if (! str_ends_with($line, "\n") || strlen($line) > 2097152) {
                    throw new RuntimeException('Truncated or oversized Overture JSONL record.');
                }
                if (++$counts['read'] > $expectedRows) {
                    throw new RuntimeException('Overture export row count exceeds the DuckDB count.');
                }
                $raw = Json::decode($line);
                if (! is_array($raw)) {
                    throw new RuntimeException('Overture JSONL contains a non-object record.');
                }
                $mapped = $this->mapper->map($raw, $release, $checkedAt, $reason);
                if ($mapped === null) {
                    $counts['skipped'][$reason] = ($counts['skipped'][$reason] ?? 0) + 1;

                    continue;
                }
                foreach (['phone', 'email', 'website'] as $contact) {
                    $counts[$contact] += $mapped[$contact] !== null ? 1 : 0;
                }
                $counts['socials'] += count((array) $mapped['social_links']) > 0 ? 1 : 0;
                $counts['cities'][$mapped['city']] = ($counts['cities'][$mapped['city']] ?? 0) + 1;
                $counts['categories'][$mapped['category_key']] = ($counts['categories'][$mapped['category_key']] ?? 0) + 1;
                $mapped['social_links'] = Json::encode($mapped['social_links']);
                $mapped['source_metadata'] = Json::encode($mapped['source_metadata']);
                $insert->execute($mapped); // Duplicate GERS IDs indicate an inconsistent export; abort.
                $counts['accepted']++;
            }
            if (! feof($stream) || $counts['read'] !== $expectedRows || $counts['accepted'] < 1) {
                throw new RuntimeException('Incomplete or empty Overture snapshot; existing snapshot was retained.');
            }
            $this->assertReplacement($destination, $counts['accepted'], $release);
            $metadata = [
                'schema_version' => '1', 'status' => 'complete', 'country' => 'IL',
                'release' => $release, 'prepared_at' => $checkedAt, 'source_checked_at' => $checkedAt,
                'row_count' => (string) $counts['accepted'], 'min_confidence' => (string) $minConfidence,
                'source_files' => Json::encode($files), 'counters' => Json::encode($counts),
                'license_url' => 'https://docs.overturemaps.org/attribution/',
            ];
            $metadataInsert = $database->prepare('INSERT INTO metadata (key, value) VALUES (?, ?)');
            foreach ($metadata as $key => $value) {
                $metadataInsert->execute([$key, $value]);
            }
            $database->commit();
            if ($database->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Overture SQLite integrity check failed.');
            }
            $insert = null;
            $metadataInsert = null;
            $database = null;
            // Do not unlink destination first: even a Windows sharing/rename failure retains it.
            if (! @rename($stage, $destination)) {
                throw new RuntimeException('Cannot atomically publish Overture snapshot; existing snapshot was retained.');
            }

            return ['database' => $destination, 'release' => $release, 'prepared_at' => $checkedAt, 'counts' => $counts];
        } finally {
            fclose($stream);
            $insert = null;
            $metadataInsert = null;
            $database = null;
            foreach ([$stage, $stage.'-journal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function export(string $duckdb, array $files, string $jsonl, string $countFile, float $minConfidence): int
    {
        $remote = false;
        foreach ($files as $file) {
            if (str_starts_with($file, 'https://')) {
                $remote = true;
            } elseif (! is_file($file)) {
                throw new RuntimeException('Overture Parquet file does not exist: '.$file);
            }
        }
        $quote = static fn (string $value): string => "'".str_replace("'", "''", str_replace('\\', '/', $value))."'";
        $sql = "SET memory_limit='768MB';\nSET threads=2;\nSET enable_progress_bar=false;\nSET preserve_insertion_order=false;\n";
        if ($remote) {
            $extensionDirectory = dirname($jsonl).'/duckdb-extensions';
            $this->directory($extensionDirectory);
            $sql .= 'SET extension_directory='.$quote($extensionDirectory).";\nINSTALL httpfs;\nLOAD httpfs;\n";
        }
        $sql .= 'CREATE TEMP TABLE export_rows AS SELECT id, names, addresses, phones, websites, emails, socials, taxonomy, confidence, operating_status, sources'
            .' FROM read_parquet(['.implode(', ', array_map($quote, $files)).'])'
            .' WHERE bbox.xmin BETWEEN 34.0 AND 36.0 AND bbox.ymin BETWEEN 29.0 AND 34.0'
            ." AND list_contains(list_transform(addresses, a -> a.country), 'IL')"
            .' AND confidence >= '.sprintf('%.8F', $minConfidence)
            ." AND (operating_status IS NULL OR operating_status NOT IN ('permanently_closed', 'temporarily_closed', 'closed'))"
            .' LIMIT '.(self::MAX_EXPORT_ROWS + 1).";\n"
            .'COPY export_rows TO '.$quote($jsonl)." (FORMAT JSON, ARRAY false);\n"
            .'COPY (SELECT count(*) AS count FROM export_rows) TO '.$quote($countFile)." (FORMAT JSON, ARRAY true);\n";
        $sqlFile = $jsonl.'.sql';
        $stdout = $jsonl.'.stdout';
        $stderr = $jsonl.'.stderr';
        $initFile = $jsonl.'.init';
        file_put_contents($sqlFile, $sql);
        file_put_contents($initFile, '');
        $process = null;
        try {
            $process = proc_open([$duckdb, '-batch', '-bail', '-init', $initFile, ':memory:'], [
                0 => ['file', $sqlFile, 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w'],
            ], $pipes, dirname($jsonl), null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start DuckDB; set --duckdb to the installed DuckDB CLI path.');
            }
            $deadline = microtime(true) + 1800;
            do {
                $status = proc_get_status($process);
                clearstatcache(true, $jsonl);
                if ((is_file($jsonl) && filesize($jsonl) > self::MAX_EXPORT_BYTES) || microtime(true) > $deadline) {
                    proc_terminate($process);
                    throw new RuntimeException('Overture export exceeded its size or 30-minute time limit.');
                }
                if ($status['running']) {
                    usleep(100000);
                }
            } while ($status['running']);
            $closeCode = proc_close($process);
            $process = null;
            $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closeCode;
            if ($exitCode !== 0) {
                $error = is_file($stderr) ? trim((string) file_get_contents($stderr, false, null, max(0, filesize($stderr) - 4096))) : '';
                throw new RuntimeException('DuckDB export failed ('.$exitCode.'): '.$error);
            }
            $count = is_file($countFile) ? Json::decode((string) file_get_contents($countFile)) : [];
            $count = $count[0]['count'] ?? null;
            if (! is_int($count) || $count < 1 || $count > self::MAX_EXPORT_ROWS) {
                throw new RuntimeException('Overture export count is empty or outside the supported range.');
            }

            return $count;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ([$sqlFile, $stdout, $stderr, $initFile] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function assertReplacement(string $destination, int $newCount, string $release): void
    {
        if (! is_file($destination)) {
            return;
        }
        $existing = new PDO('sqlite:'.$destination, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $existing->exec('PRAGMA query_only = ON');
        $metadata = $existing->query('SELECT key, value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        $existing = null;
        if (($metadata['schema_version'] ?? null) !== '1' || ($metadata['status'] ?? null) !== 'complete' || ($metadata['country'] ?? null) !== 'IL') {
            throw new RuntimeException('Destination is not a complete Overture snapshot; choose another --output path.');
        }
        if (version_compare($release, $metadata['release'] ?? '', '<') || $newCount < (int) ($metadata['row_count'] ?? 0) * 0.5) {
            throw new RuntimeException('Overture replacement is older or loses over half the rows; existing snapshot was retained. Inspect using another --output path.');
        }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create Overture preparation directory: '.$path);
        }
    }
}
