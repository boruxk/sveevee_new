<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Foursquare;

use PDO;
use RuntimeException;
use Sveevee\Worker\Http\CurlHttpClient;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;

/** Downloads a pinned, country-filtered snapshot; credentials never enter files or SQLite. */
final class DatasetPreparer
{
    public const ENDPOINT = 'https://catalog.h3-hub.foursquare.com/iceberg';

    private const MAX_ROWS = 300000;

    private const MAX_BYTES = 1073741824;

    public function __construct(private readonly PlaceMapper $mapper, private readonly ?HttpClientInterface $http = null) {}

    public function snapshot(string $token): array
    {
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('Set a valid FOURSQUARE_ACCESS_TOKEN in the worker environment file.');
        }
        $response = ($this->http ?? new CurlHttpClient)->request('GET', self::ENDPOINT.'/v1/places/namespaces/datasets/tables/places_os', [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
        ], null, ['timeout' => 60, 'max_bytes' => 8 * 1024 * 1024]);
        if ($response->status !== 200) {
            throw new RuntimeException('Foursquare catalog returned HTTP '.$response->status.'.');
        }
        $metadata = Json::decode($response->body)['metadata'] ?? [];
        $id = (string) ($metadata['current-snapshot-id'] ?? '');
        self::validateSnapshotId($id);
        foreach ($metadata['snapshots'] ?? [] as $snapshot) {
            if ((string) ($snapshot['snapshot-id'] ?? '') === $id && is_numeric($snapshot['timestamp-ms'] ?? null)) {
                return ['snapshot_id' => $id, 'release' => gmdate('Y-m-d', (int) floor($snapshot['timestamp-ms'] / 1000)),
                    'source_updated_at' => gmdate('c', (int) floor($snapshot['timestamp-ms'] / 1000))];
            }
        }
        throw new RuntimeException('Foursquare did not supply its current snapshot timestamp.');
    }

    public function prepare(string $duckdb, string $token, string $destination): array
    {
        $this->directory(dirname($destination));
        $lock = new ProcessLock($destination.'.prepare.lock');
        $lock->acquire();
        $snapshot = $this->snapshot($token);
        $prefix = $destination.'.download-'.bin2hex(random_bytes(8));
        try {
            $rows = $this->export($duckdb, $token, $snapshot['snapshot_id'], $prefix);

            return $this->importJsonl($prefix.'.jsonl', $snapshot['release'], $destination, $rows, $snapshot['snapshot_id']);
        } finally {
            foreach ([$prefix.'.jsonl', $prefix.'.count.json'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** Import a previously downloaded pinned export or an isolated test fixture. */
    public function importJsonl(string $jsonl, string $release, string $destination, int $expectedRows, string $snapshotId): array
    {
        self::validateSnapshotId($snapshotId);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $release, $date) || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new RuntimeException('Foursquare release must be a valid YYYY-MM-DD date.');
        }
        if ($expectedRows < 1 || $expectedRows > self::MAX_ROWS || ! is_file($jsonl) || filesize($jsonl) > self::MAX_BYTES) {
            throw new RuntimeException('Missing, empty or oversized Foursquare export; existing snapshot retained.');
        }
        $this->directory(dirname($destination));
        $stage = $destination.'.stage-'.bin2hex(random_bytes(8));
        $stream = fopen($jsonl, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read Foursquare export.');
        }
        $db = $insert = null;
        $checkedAt = Clock::now();
        $counts = ['read' => 0, 'accepted' => 0, 'closed' => 0, 'invalid_name' => 0, 'invalid_open' => 0, 'phone' => 0, 'email' => 0, 'website' => 0, 'socials' => 0, 'missing_city' => 0, 'missing_category' => 0];
        try {
            $db = new PDO('sqlite:'.$stage, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=DELETE; PRAGMA synchronous=FULL');
            $db->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY,value TEXT NOT NULL)');
            $db->exec('CREATE TABLE places (id TEXT PRIMARY KEY,name TEXT NOT NULL,category_key TEXT,city TEXT,street TEXT,phone TEXT,email TEXT,website TEXT,social_links TEXT NOT NULL,confidence REAL,release TEXT NOT NULL,source_url TEXT NOT NULL,source_name TEXT NOT NULL,source_checked_at TEXT NOT NULL,source_metadata TEXT NOT NULL)');
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO places VALUES (:id,:name,:category_key,:city,:street,:phone,:email,:website,:social_links,:confidence,:release,:source_url,:source_name,:source_checked_at,:source_metadata)');
            while (($line = fgets($stream, 2097153)) !== false) {
                if (! str_ends_with($line, "\n") || strlen($line) > 2097152 || ++$counts['read'] > $expectedRows) {
                    throw new RuntimeException('Truncated, oversized or unexpected extra Foursquare record.');
                }
                $raw = Json::decode($line);
                if (! is_array($raw) || array_is_list($raw) || ($raw['country'] ?? null) !== 'IL') {
                    throw new RuntimeException('The Foursquare snapshot must contain only IL records.');
                }
                $mapped = $this->mapper->map($raw, $release, $checkedAt);
                if ($mapped === null) {
                    throw new RuntimeException('Foursquare full-snapshot mapping unexpectedly discarded a record.');
                }
                $mapped['source_metadata']['snapshot_id'] = $snapshotId;
                $closed = trim((string) ($raw['date_closed'] ?? '')) !== '';
                $invalidName = ($mapped['source_metadata']['preparation_error'] ?? null) === 'invalid_business_name';
                $counts['closed'] += $closed ? 1 : 0;
                $counts['invalid_name'] += $invalidName ? 1 : 0;
                $counts['invalid_open'] += $invalidName && ! $closed ? 1 : 0;
                foreach (['phone', 'email', 'website'] as $field) {
                    $counts[$field] += $mapped[$field] !== null ? 1 : 0;
                }
                $counts['socials'] += (array) $mapped['social_links'] !== [] ? 1 : 0;
                $counts['missing_city'] += $mapped['city'] === null ? 1 : 0;
                $counts['missing_category'] += $mapped['category_key'] === null ? 1 : 0;
                $mapped['social_links'] = Json::encode($mapped['social_links']);
                $mapped['source_metadata'] = Json::encode($mapped['source_metadata']);
                $insert->execute($mapped);
                $counts['accepted']++;
            }
            if (! feof($stream) || $counts['read'] !== $expectedRows || $counts['accepted'] !== $expectedRows) {
                throw new RuntimeException('Foursquare snapshot is incomplete; existing snapshot retained.');
            }
            $this->assertReplacement($destination, $snapshotId, $expectedRows);
            $metadata = ['schema_version' => '1', 'provider' => 'foursquare_places', 'import_mode' => 'all_records',
                'status' => 'complete', 'country' => 'IL', 'row_count' => (string) $expectedRows, 'release' => $release,
                'snapshot_id' => $snapshotId, 'prepared_at' => $checkedAt, 'source_checked_at' => $checkedAt,
                'counters' => Json::encode($counts), 'catalog_endpoint' => self::ENDPOINT,
                'license_url' => 'https://www.apache.org/licenses/LICENSE-2.0'];
            $meta = $db->prepare('INSERT INTO metadata VALUES (?,?)');
            foreach ($metadata as $key => $value) {
                $meta->execute([$key, $value]);
            }
            $meta = null;
            $db->commit();
            if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Foursquare SQLite integrity check failed.');
            }
            $insert = $db = null;
            if (! @rename($stage, $destination)) {
                throw new RuntimeException('Cannot atomically publish Foursquare snapshot; existing snapshot retained.');
            }

            return ['database' => $destination, 'release' => $release, 'snapshot_id' => $snapshotId, 'counts' => $counts];
        } finally {
            fclose($stream);
            $insert = $db = null;
            foreach ([$stage, $stage.'-journal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function export(string $duckdb, string $token, string $snapshotId, string $prefix): int
    {
        $directory = str_replace('\\', '/', dirname($prefix));
        $this->directory($directory.'/duckdb-extensions');
        $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $jsonl = str_replace('\\', '/', $prefix.'.jsonl');
        $countFile = str_replace('\\', '/', $prefix.'.count.json');
        $sql = "SET memory_limit='768MB'; SET threads=1; SET enable_progress_bar=false; SET preserve_insertion_order=false;\n"
            .'SET extension_directory='.$quote($directory.'/duckdb-extensions').'; SET temp_directory='.$quote($prefix.'.temp').";\n"
            ."INSTALL httpfs; LOAD httpfs; INSTALL iceberg; LOAD iceberg;\n"
            ."SET enable_http_logging=false; SET http_timeout=120; SET http_retries=5; SET http_retry_wait_ms=500;\n"
            .'CREATE SECRET fsq_access (TYPE iceberg, TOKEN '.$quote($token).");\n"
            ."ATTACH 'places' AS fsq (TYPE iceberg, SECRET fsq_access, ENDPOINT '".self::ENDPOINT."');\n"
            .'CREATE TEMP TABLE il_places AS SELECT * EXCLUDE(geom), hex(geom) AS geom_wkb_hex FROM fsq.datasets.places_os AT (VERSION => '.$snapshotId.") WHERE country='IL' LIMIT ".(self::MAX_ROWS + 1).";\n"
            .'COPY il_places TO '.$quote($jsonl)." (FORMAT JSON, ARRAY false);\n"
            .'COPY (SELECT count(*) AS count FROM il_places) TO '.$quote($countFile)." (FORMAT JSON, ARRAY true);\n";
        // SQL containing the token goes only to stdin. Discard diagnostics, which can contain signed URLs.
        $init = $prefix.'.init';
        file_put_contents($init, '');
        $process = null;
        $pipes = [];
        try {
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $process = proc_open([$duckdb, '-batch', '-bail', '-init', $init, ':memory:'], [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']], $pipes, dirname($prefix), null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start DuckDB; configure --duckdb.');
            }
            $remaining = $sql;
            while ($remaining !== '') {
                $written = fwrite($pipes[0], $remaining);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot send preparation commands to DuckDB.');
                }
                $remaining = substr($remaining, $written);
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            $deadline = microtime(true) + 3600;
            do {
                $status = proc_get_status($process);
                clearstatcache(true, $jsonl);
                if (microtime(true) > $deadline || (is_file($jsonl) && filesize($jsonl) > self::MAX_BYTES)) {
                    throw new RuntimeException('Foursquare export exceeded its 60-minute or 1-GiB output limit.');
                }
                if ($status['running']) {
                    usleep(100000);
                }
            } while ($status['running']);
            $code = proc_close($process);
            $process = null;
            $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $code;
            if ($code !== 0) {
                // DuckDB diagnostics may include SQL, signed URLs or vended credentials: never print them.
                throw new RuntimeException('Foursquare DuckDB export failed (exit '.$code.'). Check token access, network and official iceberg/httpfs extensions.');
            }
            $count = is_file($countFile) ? (Json::decode((string) file_get_contents($countFile))[0]['count'] ?? null) : null;
            if (! is_int($count) || $count < 1 || $count > self::MAX_ROWS) {
                throw new RuntimeException('Foursquare export row count is empty or outside the supported bound.');
            }

            return $count;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (is_file($init)) {
                unlink($init);
            }
        }
    }

    private function assertReplacement(string $destination, string $snapshotId, int $rows): void
    {
        if (! is_file($destination)) {
            return;
        }
        $previous = new PDO('sqlite:'.$destination, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $metadata = $previous->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($metadata['provider'] ?? null) !== 'foursquare_places' || ($metadata['status'] ?? null) !== 'complete'
            || (($metadata['snapshot_id'] ?? null) === $snapshotId && (int) ($metadata['row_count'] ?? 0) !== $rows)) {
            throw new RuntimeException('Foursquare replacement conflicts with the existing snapshot; it was retained.');
        }
    }

    private static function validateSnapshotId(string $id): void
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new RuntimeException('Foursquare requires a numeric Iceberg snapshot ID.');
        }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create Foursquare preparation directory.');
        }
    }
}
