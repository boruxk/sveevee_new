<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\OpenStreetMap;

use PDO;
use RuntimeException;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;

/** Publish only a complete, checked country-filtered extract. Never contacts the import API. */
final class DatasetPreparer
{
    public function __construct(private readonly PlaceMapper $mapper) {}

    public function importJsonl(string $jsonl, string $manifestFile, string $destination): array
    {
        $manifest = Json::decode((string) file_get_contents($manifestFile));
        if (($manifest['provider'] ?? null) !== 'osm_places' || ($manifest['status'] ?? null) !== 'complete'
            || ($manifest['country'] ?? null) !== 'IL' || ($manifest['country_filter'] ?? null) !== 'osm_admin_boundary_IL'
            || ! is_int($manifest['row_count'] ?? null) || $manifest['row_count'] < 1 || $manifest['row_count'] > 1000000
            || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['snapshot_id'] ?? '')
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $manifest['release'] ?? '')
            || ! is_file($jsonl) || filesize($jsonl) > 1073741824
            || ($manifest['jsonl_sha256'] ?? null) !== hash_file('sha256', $jsonl)) {
            throw new RuntimeException('Incomplete, oversized or inconsistent OSM export; existing snapshot retained.');
        }
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0750, true) && ! is_dir(dirname($destination))) {
            throw new RuntimeException('Cannot create OSM snapshot directory.');
        }
        $stage = $destination.'.stage-'.bin2hex(random_bytes(8));
        $stream = fopen($jsonl, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read OSM export.');
        }
        $db = $insert = null;
        $counts = ['accepted' => 0, 'closed' => 0, 'invalid' => 0, 'phone' => 0, 'website' => 0,
            'email' => 0, 'opening_hours' => 0, 'weekly_hours' => 0, 'missing_city' => 0, 'missing_category' => 0];
        $checked = Clock::now();
        try {
            $db = new PDO('sqlite:'.$stage, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=DELETE; PRAGMA synchronous=FULL; CREATE TABLE metadata (key TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE places (id TEXT PRIMARY KEY,payload TEXT NOT NULL)');
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO places VALUES (?,?)');
            while (($line = fgets($stream, 2097153)) !== false) {
                if (! str_ends_with($line, "\n") || strlen($line) > 2097152 || ++$counts['accepted'] > $manifest['row_count']) {
                    throw new RuntimeException('Truncated, oversized or extra OSM export row.');
                }
                $mapped = $this->mapper->map(Json::decode($line), $manifest['release'], $checked);
                $metadata = &$mapped['source_metadata'];
                $metadata['snapshot_id'] = $manifest['snapshot_id'];
                $metadata['extract_url'] = $manifest['extract_url'] ?? null;
                $counts['closed'] += $metadata['lifecycle_status'] !== 'active' ? 1 : 0;
                $counts['invalid'] += isset($metadata['preparation_error']) && $metadata['lifecycle_status'] === 'active' ? 1 : 0;
                foreach (['phone', 'website'] as $field) {
                    $counts[$field] += $mapped[$field] !== null ? 1 : 0;
                }
                $counts['email'] += $mapped['contact_email'] !== null ? 1 : 0;
                $counts['opening_hours'] += $metadata['opening_hours_raw'] !== null ? 1 : 0;
                $counts['weekly_hours'] += $mapped['opening_hours'] !== [] ? 1 : 0;
                $counts['missing_city'] += ! isset($mapped['address']['city']) ? 1 : 0;
                $counts['missing_category'] += $mapped['category_key'] === null ? 1 : 0;
                $insert->execute([$metadata['source_id'], Json::encode($mapped)]);
                unset($metadata);
            }
            if (! feof($stream) || $counts['accepted'] !== $manifest['row_count']) {
                throw new RuntimeException('OSM export count mismatch; existing snapshot retained.');
            }
            if (is_file($destination)) {
                $old = new PDO('sqlite:'.$destination, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
                $oldMeta = $old->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
                $old = null;
                if (($oldMeta['provider'] ?? null) !== 'osm_places' || ($oldMeta['status'] ?? null) !== 'complete'
                    || (($oldMeta['snapshot_id'] ?? null) === $manifest['snapshot_id'] && (int) ($oldMeta['row_count'] ?? 0) !== $manifest['row_count'])) {
                    throw new RuntimeException('OSM replacement conflicts with the existing snapshot.');
                }
            }
            $metadata = ['schema_version' => '1', 'provider' => 'osm_places', 'import_mode' => 'all_records',
                'status' => 'complete', 'country' => 'IL', 'country_filter' => 'osm_admin_boundary_IL',
                'row_count' => (string) $counts['accepted'], 'release' => $manifest['release'],
                'snapshot_id' => $manifest['snapshot_id'], 'prepared_at' => $checked,
                'counters' => Json::encode($counts), 'extract_manifest' => Json::encode($manifest)];
            $meta = $db->prepare('INSERT INTO metadata VALUES (?,?)');
            foreach ($metadata as $key => $value) {
                $meta->execute([$key, $value]);
            }
            $meta = null;
            $db->commit();
            if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('OSM SQLite integrity check failed.');
            }
            $insert = $db = null;
            if (! @rename($stage, $destination)) {
                throw new RuntimeException('Cannot atomically replace the OSM snapshot.');
            }

            return ['database' => $destination, 'release' => $manifest['release'], 'snapshot_id' => $manifest['snapshot_id'], 'counts' => $counts];
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
}
