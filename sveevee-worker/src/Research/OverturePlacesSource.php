<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

/** Reads the prepared Israel extract; discovery never downloads the worldwide dataset. */
final class OverturePlacesSource implements SourceAdapterInterface
{
    private const CATEGORIES = [
        'food_catering.bakery',
        'food_catering.restaurants',
        'professionals.fast_food',
        'food_catering.cafes',
        'professionals.catering',
        'professionals.grocery_food',
        'food_catering.meat_deli',
        'food_catering.bars',
        'professionals.venues',
        'travel_leisure.hotels_guesthouses',
    ];

    private ?PDO $database = null;

    public function __construct(
        private readonly array $config,
        private readonly string $root,
        private readonly WorkerRepository $repository,
    ) {}

    public function name(): string
    {
        return 'overture_places';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 30));
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        if ($limit <= 0 || $target->neighborhood !== null || ! in_array($target->categoryKey, self::CATEGORIES, true)) {
            return;
        }

        // The city/category/id index streams one target in stable order. Cached rows must not consume the limit.
        $statement = $this->database()->prepare(
            'SELECT * FROM places WHERE city = :city AND category_key = :category '
            .'AND confidence >= :confidence AND confidence <= 1 ORDER BY id'
        );
        $statement->execute([
            'city' => $target->city,
            'category' => $target->categoryKey,
            'confidence' => max(0.0, min(1.0, (float) ($this->config['min_confidence'] ?? 0.75))),
        ]);
        $emitted = 0;
        try {
            while (($row = $statement->fetch()) !== false) {
                $business = $this->map($row);
                if ($business === null || ! $this->repository->shouldProcessUrl(
                    $this->name(), $business['source_url'], $this->refreshAfterDays(), SourceFingerprint::hash($business),
                )) {
                    continue;
                }
                yield $business;
                if (++$emitted >= $limit) {
                    return;
                }
            }
        } finally {
            $statement->closeCursor();
        }
    }

    private function database(): PDO
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $path = trim((string) ($this->config['database_path'] ?? 'var/overture.sqlite'));
        if ($path === '') {
            throw new RuntimeException('Overture database_path must name a prepared SQLite extract.');
        }
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = rtrim($this->root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Prepared Overture Places database not found or unreadable: {$path}");
        }

        try {
            $database = new PDO('sqlite:'.$path, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $database->exec('PRAGMA query_only = ON');
            $metadata = $database->query('SELECT key, value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
            if (($metadata['schema_version'] ?? null) !== '1') {
                throw new RuntimeException('Unsupported prepared Overture database schema; rebuild the extract with the current preparation command.');
            }
            if (($metadata['status'] ?? null) !== 'complete' || ($metadata['country'] ?? null) !== 'IL') {
                throw new RuntimeException('Prepared Overture database must be a complete Israel extract; rebuild the extract.');
            }
            $columns = array_column($database->query('PRAGMA table_info(places)')->fetchAll(), 'name');
            $required = [
                'id', 'name', 'category_key', 'city', 'street', 'phone', 'email', 'website', 'social_links',
                'confidence', 'release', 'source_url', 'source_name', 'source_checked_at', 'source_metadata',
            ];
            if (array_diff($required, $columns) !== []) {
                throw new RuntimeException('Prepared Overture database is missing required place columns; rebuild the extract.');
            }

            return $this->database = $database;
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to read the prepared Overture Places database: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function map(array $row): ?array
    {
        $name = $this->text($row['name']);
        $id = $this->text($row['id']);
        if ($name === null || $id === null) {
            return null;
        }
        $url = $this->text($row['source_url']);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException("Overture place {$id} has an invalid source URL; rebuild the extract.");
        }
        $metadata = $this->jsonObject($row['source_metadata'], 'source_metadata', $id);
        $socials = $this->jsonObject($row['social_links'], 'social_links', $id);
        $business = [
            'type' => 'business',
            'name' => $name,
            'category_key' => $row['category_key'],
            'address' => ['city' => $row['city']],
            'service_areas' => [$row['city']],
            'source_name' => $this->text($row['source_name']) ?? 'Overture Maps Places',
            'source_url' => $url,
            'source_checked_at' => $row['source_checked_at'],
            // Provenance is retained for audit; SourceFingerprint excludes metadata from business content hashes.
            'source_metadata' => array_replace($metadata, [
                'overture_id' => $id,
                'release' => $row['release'],
                'confidence' => (float) $row['confidence'],
            ]),
        ];
        if (($street = $this->text($row['street'])) !== null) {
            $business['address']['street'] = $street;
        }
        foreach (['phone' => 'phone', 'email' => 'contact_email', 'website' => 'website'] as $column => $field) {
            if (($value = $this->text($row[$column])) !== null) {
                $business[$field] = $value;
            }
        }
        if ($socials !== []) {
            $business['socials'] = $socials;
        }

        return $business;
    }

    private function jsonObject(mixed $value, string $field, string $id): array
    {
        try {
            $decoded = is_string($value) ? Json::decode($value) : null;
        } catch (JsonException $exception) {
            throw new RuntimeException("Overture place {$id} has invalid {$field} JSON; rebuild the extract.", 0, $exception);
        }
        if (! is_string($value) || ! str_starts_with(ltrim($value), '{')
            || ! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException("Overture place {$id} has invalid {$field}; expected a JSON object.");
        }

        return $decoded;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
