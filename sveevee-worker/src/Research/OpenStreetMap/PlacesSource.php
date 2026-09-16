<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\OpenStreetMap;

use PDO;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Research\CursorSourceInterface;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

final class PlacesSource implements CursorSourceInterface
{
    private ?PDO $database = null;
    private array $metadata = [];
    private ?string $snapshotKey = null;
    private ?string $outstanding = null;
    private ?string $lastAcknowledged = null;

    public function __construct(private readonly array $config, private readonly string $root, private readonly WorkerRepository $repository) {}

    public function name(): string { return 'osm_places'; }
    public function refreshAfterDays(): int { return max(1, (int) ($this->config['refresh_after_days'] ?? 30)); }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        if ($target->fullSourceProvider() !== $this->name() || $limit < 1) {
            return;
        }
        $database = $this->database();
        $scan = $this->repository->sourceScanProgress($this->snapshotKey);
        $statement = $database->prepare('SELECT id,payload FROM places WHERE id > ? ORDER BY id');
        $statement->execute([$scan['last_id']]);
        $emitted = 0;
        try {
            while (($row = $statement->fetch()) !== false) {
                $business = Json::decode($row['payload']);
                if (($business['source_metadata']['source_id'] ?? null) !== $row['id']
                    || ! preg_match('~^(node|way|relation)/[1-9][0-9]*$~D', $row['id'])
                    || ($business['source_url'] ?? null) !== 'https://www.openstreetmap.org/'.$row['id']
                    || ($business['source_metadata']['country'] ?? null) !== 'IL') {
                    throw new RuntimeException('OSM snapshot row identity is inconsistent.');
                }
                $this->outstanding = $row['id'];
                if (($business['source_metadata']['lifecycle_status'] ?? null) !== 'active'
                    || isset($business['source_metadata']['preparation_error'])
                    || ! $this->repository->shouldProcessUrl($this->name(), $business['source_url'], $this->refreshAfterDays(), SourceFingerprint::hash($business))) {
                    $this->acknowledge($business);
                    continue;
                }
                yield $business;
                if ($this->outstanding !== null) {
                    throw new RuntimeException('OSM source row must be durably acknowledged before advancing.');
                }
                if (++$emitted >= $limit) {
                    return;
                }
            }
        } finally {
            $statement->closeCursor();
        }
    }

    public function acknowledge(array $raw): void
    {
        $id = $raw['source_metadata']['source_id'] ?? null;
        if ($id === $this->lastAcknowledged) {
            return;
        }
        if ($this->snapshotKey === null || $this->outstanding === null || $id !== $this->outstanding
            || ($raw['source_url'] ?? null) !== 'https://www.openstreetmap.org/'.$id) {
            throw new RuntimeException('OSM acknowledgement does not match the outstanding row.');
        }
        $this->repository->acknowledgeSourceRow($this->snapshotKey, $id);
        $this->lastAcknowledged = $id;
        $this->outstanding = null;
    }

    public function progress(): array
    {
        $database = $this->database();
        $scan = $this->repository->sourceScanProgress($this->snapshotKey);
        $total = (int) $this->metadata['row_count'];
        $counts = $database->prepare("SELECT SUM(json_extract(payload,'$.source_metadata.lifecycle_status') <> 'active') AS closed, SUM(json_extract(payload,'$.source_metadata.lifecycle_status') = 'active' AND json_extract(payload,'$.source_metadata.preparation_error') IS NOT NULL) AS invalid FROM places WHERE id <= ?");
        $counts->execute([$scan['last_id']]);
        $excluded = $counts->fetch();
        $scanned = min($total, (int) $scan['scanned']);

        return ['release' => $this->metadata['release'], 'total' => $total, 'scanned' => $scanned,
            'remaining' => max(0, $total - $scanned), 'closed' => (int) $excluded['closed'], 'invalid' => (int) $excluded['invalid'],
            ...$this->repository->sourceQueueCounts($this->name())];
    }

    private function database(): PDO
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $path = trim((string) ($this->config['database_path'] ?? '')) ?: 'var/osm/osm.sqlite';
        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = $this->root.'/'.$path;
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Prepared OSM Israel snapshot missing; run bin/prepare-osm.php first.');
        }
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $database->exec('PRAGMA query_only=ON');
        $meta = $database->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['schema_version' => '1', 'provider' => 'osm_places', 'import_mode' => 'all_records', 'status' => 'complete',
            'country' => 'IL', 'country_filter' => 'osm_admin_boundary_IL'] as $key => $expected) {
            if (($meta[$key] ?? null) !== $expected) {
                throw new RuntimeException('OSM snapshot has invalid '.$key.' metadata.');
            }
        }
        if (! ctype_digit($meta['row_count'] ?? '') || (int) $meta['row_count'] < 1
            || ! preg_match('/^[a-f0-9]{64}$/D', $meta['snapshot_id'] ?? '')
            || (int) $database->query('SELECT COUNT(*) FROM places')->fetchColumn() !== (int) $meta['row_count']) {
            throw new RuntimeException('OSM snapshot count or snapshot identity is inconsistent.');
        }
        $this->metadata = $meta;
        $this->snapshotKey = 'osm_places:'.Json::hash(array_intersect_key($meta, array_flip(['schema_version', 'provider', 'country_filter', 'snapshot_id', 'row_count'])));

        return $this->database = $database;
    }
}
