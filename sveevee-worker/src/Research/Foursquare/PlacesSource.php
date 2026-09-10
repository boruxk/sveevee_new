<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Foursquare;

use PDO;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Research\CursorSourceInterface;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

/** Offline Israel snapshot reader. Every accepted row is durably acknowledged before advancing. */
final class PlacesSource implements CursorSourceInterface
{
    private ?PDO $database = null;

    private array $metadata = [];

    private ?string $snapshotKey = null;

    private ?string $outstanding = null;

    private ?string $lastAcknowledged = null;

    public function __construct(private readonly array $config, private readonly string $root, private readonly WorkerRepository $repository) {}

    public function name(): string
    {
        return 'foursquare_places';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 30));
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        if ($target->fullSourceProvider() !== $this->name() || $limit < 1) {
            return;
        }
        $database = $this->database();
        $progress = $this->repository->sourceScanProgress($this->snapshotKey);
        $statement = $database->prepare('SELECT * FROM places WHERE id > ? ORDER BY id');
        $statement->execute([$progress['last_id']]);
        $emitted = 0;
        try {
            while (($row = $statement->fetch()) !== false) {
                $business = $this->map($row);
                $this->outstanding = $row['id'];
                if (($business['source_metadata']['date_closed'] ?? null) !== null
                    && trim((string) $business['source_metadata']['date_closed']) !== '') {
                    // Closed records stay in the snapshot and are counted in progress, never published.
                    $this->acknowledge($business);

                    continue;
                }
                if (($business['source_metadata']['preparation_error'] ?? null) === 'invalid_business_name') {
                    // Preserve unusable original names in the snapshot without exposing a replacement name.
                    $this->acknowledge($business);

                    continue;
                }
                if (! $this->repository->shouldProcessUrl($this->name(), $business['source_url'], $this->refreshAfterDays(), SourceFingerprint::hash($business))) {
                    $this->acknowledge($business);

                    continue;
                }
                yield $business;
                if ($this->outstanding !== null || ++$emitted >= $limit) {
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
            || ($raw['source_url'] ?? null) !== 'https://foursquare.com/placemakers/review-place/'.$id) {
            throw new RuntimeException('Foursquare acknowledgement does not match the current source record.');
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
        $scanned = min($total, (int) $scan['scanned']);
        $closed = $database->prepare("SELECT COUNT(*) FROM places WHERE id <= ? AND NULLIF(TRIM(json_extract(source_metadata, '$.date_closed')), '') IS NOT NULL");
        $closed->execute([$scan['last_id']]);
        $invalid = $database->prepare("SELECT COUNT(*) FROM places WHERE id <= ? AND json_extract(source_metadata, '$.preparation_error') = 'invalid_business_name' AND NULLIF(TRIM(json_extract(source_metadata, '$.date_closed')), '') IS NULL");
        $invalid->execute([$scan['last_id']]);

        return ['release' => $this->metadata['release'], 'total' => $total, 'scanned' => $scanned,
            'remaining' => max(0, $total - $scanned), 'closed' => (int) $closed->fetchColumn(),
            'invalid' => (int) $invalid->fetchColumn(),
            ...$this->repository->foursquareQueueCounts()];
    }

    private function database(): PDO
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $path = trim((string) ($this->config['database_path'] ?? 'var/foursquare/foursquare.sqlite'));
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = rtrim($this->root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Prepared Foursquare Israel database not found or unreadable; run prepare-foursquare.php.');
        }
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $database->exec('PRAGMA query_only=ON');
        $metadata = $database->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['schema_version' => '1', 'provider' => 'foursquare_places', 'import_mode' => 'all_records', 'status' => 'complete', 'country' => 'IL'] as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new RuntimeException('Prepared Foursquare database has invalid '.$key.' metadata; rebuild it.');
            }
        }
        if (! ctype_digit((string) ($metadata['row_count'] ?? '')) || (int) $metadata['row_count'] < 1
            || preg_match('/^[1-9][0-9]*$/D', (string) ($metadata['snapshot_id'] ?? '')) !== 1
            || trim((string) ($metadata['release'] ?? '')) === ''
            || (int) $database->query('SELECT COUNT(*) FROM places')->fetchColumn() !== (int) $metadata['row_count']) {
            throw new RuntimeException('Prepared Foursquare database has inconsistent progress metadata or row count.');
        }
        $required = ['id', 'name', 'category_key', 'city', 'street', 'phone', 'email', 'website', 'social_links',
            'confidence', 'release', 'source_url', 'source_name', 'source_checked_at', 'source_metadata'];
        $columns = array_column($database->query('PRAGMA table_info(places)')->fetchAll(), 'name');
        if (array_diff($required, $columns) !== []) {
            throw new RuntimeException('Prepared Foursquare database lacks required columns.');
        }
        $this->metadata = $metadata;
        $identity = [];
        foreach (['schema_version', 'provider', 'import_mode', 'country', 'release', 'snapshot_id', 'row_count'] as $key) {
            $identity[$key] = $metadata[$key];
        }
        $this->snapshotKey = 'foursquare_places:'.Json::hash($identity);

        return $this->database = $database;
    }

    private function map(array $row): array
    {
        $metadata = $this->object($row['source_metadata']);
        if (! is_string($row['id']) || preg_match('/^[0-9a-f]{24}$/D', $row['id']) !== 1
            || ! is_string($row['name']) || (trim($row['name']) === '' && ($metadata['preparation_error'] ?? null) !== 'invalid_business_name')) {
            throw new RuntimeException('Prepared Foursquare record has invalid identity fields.');
        }
        $url = 'https://foursquare.com/placemakers/review-place/'.$row['id'];
        if ($row['source_url'] !== $url) {
            throw new RuntimeException('Prepared Foursquare record has an inconsistent source URL.');
        }
        if (($metadata['source_id'] ?? null) !== $row['id'] || ($metadata['country'] ?? null) !== 'IL'
            || ! array_key_exists('date_closed', $metadata)) {
            throw new RuntimeException('Prepared Foursquare record has inconsistent source metadata.');
        }
        $metadata['snapshot_id'] = $this->metadata['snapshot_id'];

        return ['type' => 'business', 'name' => $row['name'], 'category_key' => $row['category_key'],
            'address' => ['city' => $row['city'], 'street' => $row['street']],
            'phone' => $row['phone'], 'contact_email' => $row['email'], 'website' => $row['website'],
            'socials' => $this->object($row['social_links']), 'source_name' => $row['source_name'],
            'source_url' => $url, 'source_checked_at' => $row['source_checked_at'], 'source_metadata' => $metadata];
    }

    private function object(mixed $json): array
    {
        if (! is_string($json) || ! str_starts_with(ltrim($json), '{')) {
            throw new RuntimeException('Prepared Foursquare metadata must be a JSON object.');
        }
        $data = Json::decode($json);
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException('Prepared Foursquare metadata must be a JSON object.');
        }

        return $data;
    }
}
