<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use PDO;
use RuntimeException;
use Sveevee\Worker\Api\ClosedBusinessGateway;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Support\Json;

/** Positive closure evidence only; the backend protects ownership and records durable tombstones. */
final class ClosedBusinessRemovalService
{
    private const STATUSES = ['would_remove', 'removed', 'already_removed', 'protected_claimed', 'review_required', 'unmatched'];

    public function __construct(private readonly ClosedBusinessGateway $api, private readonly int $batchSize = 100)
    {
        if ($batchSize < 1 || $batchSize > 100) {
            throw new \InvalidArgumentException('Closure batch size must be between 1 and 100.');
        }
    }

    public function run(string $path, bool $dryRun, RunReport $report): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Prepared Foursquare closure snapshot is missing or unreadable.');
        }
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $database->exec('PRAGMA query_only=ON');
        $metadata = $database->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['schema_version' => '1', 'provider' => 'foursquare_places', 'import_mode' => 'all_records', 'status' => 'complete', 'country' => 'IL'] as $key => $expected) {
            if (($metadata[$key] ?? null) !== $expected) {
                throw new RuntimeException('Invalid Foursquare closure snapshot metadata: '.$key.'.');
            }
        }
        $release = $metadata['release'] ?? '';
        if (! $this->validDate($release) || $release > gmdate('Y-m-d')
            || preg_match('/^[1-9][0-9]{0,119}$/D', $metadata['snapshot_id'] ?? '') !== 1
            || ! ctype_digit($metadata['row_count'] ?? '') || (int) $metadata['row_count'] < 1
            || (int) $database->query('SELECT COUNT(*) FROM places')->fetchColumn() !== (int) $metadata['row_count']) {
            throw new RuntimeException('Foursquare closure snapshot has inconsistent identity, release or row count.');
        }
        $progress = ['snapshot_id' => $metadata['snapshot_id'], 'release' => $release,
            'total' => (int) $metadata['row_count'], 'scanned' => 0, 'closed' => 0, 'invalid_evidence' => 0,
            ...array_fill_keys(self::STATUSES, 0), 'failed' => 0];
        $report->source('foursquare_places', 0);
        $report->closedBusinessesProgress($progress);
        $statement = $database->query('SELECT id,city,street,release,source_url,source_metadata FROM places ORDER BY id');
        $batch = [];
        try {
            while (($row = $statement->fetch()) !== false) {
                $progress['scanned']++;
                $data = Json::decode($row['source_metadata']);
                if (! is_array($data) || ! array_key_exists('date_closed', $data)) {
                    throw new RuntimeException('Foursquare snapshot is missing explicit closure metadata.');
                }
                if ($data['date_closed'] === null || $data['date_closed'] === '') {
                    $report->closedBusinessesProgress($progress);

                    continue;
                }
                $progress['closed']++;
                $report->increment('found');
                $report->source('foursquare_places');
                $evidence = $this->evidence($row, $data, $release);
                if ($evidence === null) {
                    $progress['invalid_evidence']++;
                    $report->increment('review');
                    $report->closedBusinessesProgress($progress);

                    continue;
                }
                $batch[] = $evidence;
                $report->closedBusinessesProgress($progress);
                if (count($batch) === $this->batchSize) {
                    $this->flush($batch, $dryRun, $progress, $report);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->flush($batch, $dryRun, $progress, $report);
            }
        } finally {
            $statement->closeCursor();
            $report->closedBusinessesProgress($progress);
        }

        return $progress;
    }

    private function evidence(array $row, array $metadata, string $release): ?array
    {
        $id = $row['id'];
        $date = $metadata['date_closed'];
        if (! is_string($id) || preg_match('/^[0-9a-f]{24}$/D', $id) !== 1
            || ($metadata['source_id'] ?? null) !== $id || ($metadata['country'] ?? null) !== 'IL'
            || $row['source_url'] !== 'https://foursquare.com/placemakers/review-place/'.$id
            || $row['release'] !== $release || ! $this->validDate($date) || $date > $release) {
            return null;
        }
        $original = $metadata['original_record'] ?? [];
        if (! is_array($original)
            || (array_key_exists('date_closed', $original) && $original['date_closed'] !== $date)
            || (array_key_exists('country', $original) && $original['country'] !== 'IL')
            || (array_key_exists('fsq_place_id', $original) && $original['fsq_place_id'] !== $id)) {
            return null;
        }
        $address = [];
        foreach (['city' => 120, 'street' => 300] as $field => $maximum) {
            $value = $row[$field];
            if ($value !== null && (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum)) {
                return null;
            }
            if ($value !== null && trim($value) !== '') {
                $address[$field] = $value;
            }
        }

        return ['source_id' => $id, 'date_closed' => $date, 'country' => 'IL', ...($address === [] ? [] : ['address' => $address])];
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) !== 1) {
            return false;
        }

        return $value >= '1900-01-01' && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function flush(array $batch, bool $dryRun, array &$progress, RunReport $report): void
    {
        try {
            $response = $this->api->removeClosedBusinesses([
                'snapshot_id' => $progress['snapshot_id'], 'release' => $progress['release'],
                'dry_run' => $dryRun, 'businesses' => $batch,
            ]);
            $items = $response['items'] ?? null;
            if (($response['dry_run'] ?? null) !== $dryRun || ($response['snapshot_id'] ?? null) !== $progress['snapshot_id']
                || ! is_array($items) || ! array_is_list($items) || count($items) !== count($batch)) {
                throw new RuntimeException('Closure API returned an inconsistent batch response.');
            }
            $expected = array_fill_keys(array_column($batch, 'source_id'), true);
            $counts = array_fill_keys(self::STATUSES, 0);
            foreach ($items as $item) {
                $id = $item['source_id'] ?? null;
                $status = $item['status'] ?? null;
                if (! is_string($id) || ! isset($expected[$id]) || ! in_array($status, self::STATUSES, true)
                    || ($dryRun && $status === 'removed') || (! $dryRun && $status === 'would_remove')) {
                    throw new RuntimeException('Closure API returned an invalid item identity or status.');
                }
                unset($expected[$id]);
                $counts[$status]++;
            }
            foreach ($counts as $status => $count) {
                $progress[$status] += $count;
            }
            $report->increment('review', $counts['review_required'] + $counts['protected_claimed']);
        } catch (\Throwable $error) {
            $progress['failed'] += count($batch);
            throw $error;
        } finally {
            $report->closedBusinessesProgress($progress);
        }
    }
}
