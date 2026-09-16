<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use PDO;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessLocationIdentity;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;

/** Stages bounded, identity-proven repairs locally; never calls the public API or rewinds a source. */
final class CatalogRepairService
{
    private array $summary = [];

    private array $checkedBusinesses = [];

    private array $stagedBusinesses = [];

    private array $previewCompleted = [];

    private array $pendingBatchBusinesses = [];

    private bool $hasTracker;

    public function __construct(
        private readonly PDO $database,
        private readonly BusinessNormalizer $normalizer,
        private readonly BusinessMerger $merger,
        private readonly ?OverturePlacesSource $overture = null,
    ) {
        $this->hasTracker = $this->database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='catalog_repairs'")->fetchColumn() > 0;
    }

    public function run(string $provider, bool $apply = false, int $limit = 9000): array
    {
        if (! in_array($provider, ['overture_places', 'foursquare_places'], true) || $limit < 1 || $limit > 9000) {
            throw new RuntimeException('Catalog repair supports Overture or Foursquare with a limit between 1 and 9000.');
        }
        if ($provider === 'overture_places' && $this->overture === null) {
            throw new RuntimeException('Overture catalog repair requires its verified prepared snapshot.');
        }
        $this->summary = ['provider' => $provider, 'apply' => $apply, 'limit' => $limit, 'examined' => 0,
            'would_queue' => 0, 'queued' => 0, 'confirmed' => 0, 'already_confirmed' => 0,
            'waiting' => 0, 'protected' => 0, 'blocked' => 0, 'beyond_limit' => 0, 'reasons' => []];
        $this->checkedBusinesses = $this->stagedBusinesses = $this->previewCompleted = [];
        $this->pendingBatchBusinesses = array_fill_keys($this->database->query("SELECT DISTINCT i.business_id FROM import_batches b JOIN import_batch_items i ON i.client_import_id=b.client_import_id WHERE b.status='pending'")->fetchAll(PDO::FETCH_COLUMN), true);
        if ($apply) {
            $this->createTracker();
        }
        $last = 0;
        do {
            $cohort = $this->hasTracker ? ' OR EXISTS (SELECT 1 FROM catalog_repairs r WHERE r.provider=s.adapter AND r.business_id=b.id)' : '';
            $condition = $provider === 'foursquare_places'
                ? "b.status='failed' AND b.last_error_code='city' AND json_extract(b.payload_json,'$.source.provider')='foursquare_places'"
                : "(COALESCE(json_extract(b.payload_json,'$.source.provider'),'')<>'overture_places' OR COALESCE(json_extract(b.payload_json,'$.source.url'),'')<>s.source_url{$cohort})";
            $query = $this->database->prepare("SELECT s.id AS source_row_id,s.source_url,s.raw_json,s.raw_hash,b.* FROM business_sources s JOIN businesses b ON b.id=s.business_id WHERE s.adapter=? AND s.id>? AND {$condition} ORDER BY s.id LIMIT 100");
            $query->execute([$provider, $last]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $query->closeCursor();
            foreach ($rows as $row) {
                $last = (int) $row['source_row_id'];
                $this->summary['examined']++;
                if ($provider === 'foursquare_places') {
                    $this->foursquare($row, $apply);
                } else {
                    $this->overture($row, $apply);
                }
            }
        } while (count($rows) === 100);

        return $this->summary;
    }

    private function overture(array $row, bool $apply): void
    {
        $businessId = (int) $row['id'];
        $this->confirmPrevious($row, $apply);
        $payload = Json::decode($row['payload_json']);
        $id = $this->sourceId('overture_places', $row['source_url']);
        if ($id === null || ! $this->uniqueSource('overture_places', $row['source_url'])) {
            $this->count('blocked', 'ambiguous_or_invalid_source');

            return;
        }
        $tracker = $this->tracker('overture_places', $id);
        if (($tracker['status'] ?? null) === 'confirmed' || isset($this->previewCompleted[$id])) {
            $this->summary['already_confirmed']++;

            return;
        }
        if (in_array($row['status'], ['review', 'claimed', 'closed'], true)) {
            $this->count('protected', $row['status']);

            return;
        }
        if (in_array($row['status'], ['pending', 'queued'], true) || isset($this->stagedBusinesses[$businessId]) || isset($this->pendingBatchBusinesses[$businessId])) {
            $this->count('waiting', 'business_already_pending');

            return;
        }
        if ($this->activeRepair($businessId) || ($tracker !== null && $tracker['status'] !== 'confirmed')) {
            $this->count('blocked', 'previous_repair_not_confirmed');

            return;
        }
        if (! in_array($row['status'], ['imported', 'updated', 'duplicate'], true)
            || filter_var($row['sveevee_page_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->count('blocked', 'missing_successful_page_association');

            return;
        }
        $baseline = $this->baseline($businessId, $payload);
        if ($baseline === $id) {
            return; // The original full-source payload is outside this repair cohort, not a claimed repair success.
        }
        try {
            $oldRaw = Json::decode($row['raw_json']);
            if (($oldRaw['source_url'] ?? null) !== $row['source_url'] || ! $this->metadataIdentifies($oldRaw['source_metadata'] ?? [], $id)) {
                throw new RuntimeException('Unverified original source identity.');
            }
            $raw = $this->overture->preparedPlace($id);
            if ($raw === null || ($raw['source_url'] ?? null) !== $row['source_url'] || ! $this->metadataIdentifies($raw['source_metadata'] ?? [], $id)) {
                throw new RuntimeException('The source ID is absent or inconsistent in the full snapshot.');
            }
            $candidate = $this->normalizer->normalize($raw, ResearchTarget::overtureAll(), 'overture_places');
            if (BusinessLocationIdentity::name($payload) !== BusinessLocationIdentity::name($candidate->data)
                || BusinessLocationIdentity::sourceConflict($payload, $candidate->data) !== null) {
                throw new RuntimeException('The source snapshot conflicts with the known local business.');
            }
            $next = $this->merger->mergeResearchData($payload, $candidate->data);
            $next['id'] = (int) $row['sveevee_page_id'];
        } catch (\Throwable) {
            $this->count('blocked', 'source_or_location_evidence_invalid');

            return;
        }
        $this->stage('overture_places', $id, $row, $next, $baseline, (string) $raw['source_metadata']['release'], $apply);
    }

    private function foursquare(array $row, bool $apply): void
    {
        if (isset($this->pendingBatchBusinesses[(int) $row['id']]) || isset($this->stagedBusinesses[(int) $row['id']])) {
            $this->count('waiting', 'business_already_pending');

            return;
        }
        $payload = Json::decode($row['payload_json']);
        $id = $this->sourceId('foursquare_places', $row['source_url']);
        if ($id === null || ($payload['source']['id'] ?? null) !== $id
            || ($payload['source']['url'] ?? null) !== $row['source_url']
            || ! $this->uniqueSource('foursquare_places', $row['source_url'])) {
            $this->count('blocked', 'invalid_foursquare_source');

            return;
        }
        if ($this->tracker('foursquare_places', $id) !== null) {
            $this->count('blocked', 'city_repair_already_attempted');

            return;
        }
        $this->stage('foursquare_places', $id, $row, $payload, $id,
            (string) ($payload['source']['metadata']['release'] ?? ''), $apply);
    }

    private function stage(string $provider, string $sourceId, array $row, array $payload, ?string $baseline, string $release, bool $apply): void
    {
        if ($this->summary['would_queue'] >= $this->summary['limit']) {
            $this->summary['beyond_limit']++;

            return;
        }
        $businessId = (int) $row['id'];
        $hash = Json::hash($payload);
        if ($apply) {
            $this->database->beginTransaction();
            try {
                $update = $this->database->prepare("UPDATE businesses SET payload_json=?,payload_hash=?,status='pending',last_error_code=NULL,last_error_message=NULL WHERE id=? AND payload_hash=? AND status=? AND attempts=? AND sveevee_page_id IS ?");
                $update->execute([Json::encode($payload), $hash, $businessId, $row['payload_hash'], $row['status'], $row['attempts'], $row['sveevee_page_id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The local business changed during repair staging.');
                }
                $this->database->prepare('INSERT INTO catalog_repairs (provider,source_id,business_id,page_id,baseline_source_id,release,queued_payload_hash,attempts_before,batch_rowid_before,original_status,original_error_code,original_error_message,status,queued_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$provider, $sourceId, $businessId, $row['sveevee_page_id'], $baseline, $release, $hash,
                        (int) $row['attempts'], (int) $this->database->query('SELECT COALESCE(MAX(rowid),0) FROM import_batches')->fetchColumn(),
                        $row['status'], $row['last_error_code'], $row['last_error_message'], 'queued', Clock::now()]);
                $this->database->commit();
                $this->summary['queued']++;
            } catch (\Throwable $error) {
                $this->database->rollBack();
                throw $error;
            }
        }
        $this->summary['would_queue']++;
        $this->stagedBusinesses[$businessId] = true;
    }

    private function confirmPrevious(array $row, bool $apply): void
    {
        $businessId = (int) $row['id'];
        if (! $this->hasTracker || isset($this->checkedBusinesses[$businessId])) {
            return;
        }
        $this->checkedBusinesses[$businessId] = true;
        $query = $this->database->prepare("SELECT * FROM catalog_repairs WHERE provider='overture_places' AND business_id=? AND status='queued'");
        $query->execute([$businessId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $tracker) {
            $payload = Json::decode($row['payload_json']);
            if (in_array($row['status'], ['imported', 'updated'], true)
                && (int) $row['sveevee_page_id'] === (int) $tracker['page_id']
                && $row['payload_hash'] === $tracker['queued_payload_hash']
                && ($payload['source']['provider'] ?? null) === 'overture_places'
                && ($payload['source']['id'] ?? null) === $tracker['source_id']
                && (int) $row['attempts'] > (int) $tracker['attempts_before']
                && ! isset($this->pendingBatchBusinesses[$businessId])
                && $this->completedBatchConfirms($tracker)) {
                if ($apply) {
                    $this->database->prepare("UPDATE catalog_repairs SET status='confirmed',confirmed_at=? WHERE provider='overture_places' AND source_id=? AND status='queued'")
                        ->execute([Clock::now(), $tracker['source_id']]);
                }
                $this->previewCompleted[$tracker['source_id']] = true;
                $this->summary['confirmed']++;
            }
        }
    }

    private function completedBatchConfirms(array $tracker): bool
    {
        $query = $this->database->prepare("SELECT b.request_json,b.response_json,i.position FROM import_batches b JOIN import_batch_items i ON i.client_import_id=b.client_import_id WHERE b.rowid>? AND b.status='completed' AND i.business_id=? AND i.payload_hash=?");
        $query->execute([(int) $tracker['batch_rowid_before'], (int) $tracker['business_id'], $tracker['queued_payload_hash']]);
        while ($batch = $query->fetch(PDO::FETCH_ASSOC)) {
            $position = (int) $batch['position'];
            $request = Json::decode($batch['request_json']);
            $submitted = $request['businesses'][$position - 1] ?? [];
            if (($submitted['source']['provider'] ?? null) !== $tracker['provider']
                || ($submitted['source']['id'] ?? null) !== $tracker['source_id']
                || (int) ($submitted['id'] ?? 0) !== (int) $tracker['page_id']) {
                continue;
            }
            $response = Json::decode($batch['response_json']);
            foreach ($response['items'] ?? [] as $item) {
                if ((int) ($item['position'] ?? 0) === $position
                    && in_array($item['status'] ?? null, ['created', 'updated'], true)
                    && (int) ($item['business']['id'] ?? 0) === (int) $tracker['page_id']) {
                    return true;
                }
            }
        }

        return false;
    }

    private function activeRepair(int $businessId): bool
    {
        if (! $this->hasTracker) {
            return false;
        }
        $query = $this->database->prepare("SELECT source_id FROM catalog_repairs WHERE provider='overture_places' AND business_id=? AND status<>'confirmed'");
        $query->execute([$businessId]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (! isset($this->previewCompleted[$id])) {
                return true;
            }
        }

        return false;
    }

    private function baseline(int $businessId, array $payload): ?string
    {
        if ($this->hasTracker) {
            $query = $this->database->prepare("SELECT baseline_source_id FROM catalog_repairs WHERE provider='overture_places' AND business_id=? ORDER BY queued_at,source_id LIMIT 1");
            $query->execute([$businessId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row['baseline_source_id'];
            }
        }

        return ($payload['source']['provider'] ?? null) === 'overture_places' ? ($payload['source']['id'] ?? null) : null;
    }

    private function tracker(string $provider, string $id): ?array
    {
        if (! $this->hasTracker) {
            return null;
        }
        $query = $this->database->prepare('SELECT * FROM catalog_repairs WHERE provider=? AND source_id=?');
        $query->execute([$provider, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function sourceId(string $provider, mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $pattern = $provider === 'overture_places'
            ? '~^https://explore\.overturemaps\.org/\?feature=places\.place\.([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})$~D'
            : '~^https://foursquare\.com/placemakers/review-place/([0-9a-f]{24})$~D';

        return preg_match($pattern, $url, $matches) === 1 ? $matches[1] : null;
    }

    private function metadataIdentifies(mixed $metadata, string $id): bool
    {
        if (! is_array($metadata) || (($metadata['overture_id'] ?? null) !== $id && ($metadata['gers_id'] ?? null) !== $id)) {
            return false;
        }
        foreach (['overture_id', 'gers_id'] as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== $id) {
                return false;
            }
        }

        return true;
    }

    private function uniqueSource(string $provider, string $url): bool
    {
        $query = $this->database->prepare('SELECT COUNT(DISTINCT business_id) FROM business_sources WHERE adapter=? AND source_url=?');
        $query->execute([$provider, $url]);

        return (int) $query->fetchColumn() === 1;
    }

    private function count(string $metric, string $reason): void
    {
        $this->summary[$metric]++;
        $this->summary['reasons'][$reason] = ($this->summary['reasons'][$reason] ?? 0) + 1;
    }

    private function createTracker(): void
    {
        $this->database->exec('CREATE TABLE IF NOT EXISTS catalog_repairs (
            provider TEXT NOT NULL,source_id TEXT NOT NULL,business_id INTEGER NOT NULL,page_id INTEGER,
            baseline_source_id TEXT,release TEXT NOT NULL,queued_payload_hash TEXT NOT NULL,attempts_before INTEGER NOT NULL,batch_rowid_before INTEGER NOT NULL,
            original_status TEXT NOT NULL,original_error_code TEXT,original_error_message TEXT,
            status TEXT NOT NULL,queued_at TEXT NOT NULL,confirmed_at TEXT,PRIMARY KEY(provider,source_id)
        )');
        $this->database->exec('CREATE INDEX IF NOT EXISTS catalog_repairs_business_idx ON catalog_repairs(provider,business_id,status)');
        $this->database->exec('CREATE INDEX IF NOT EXISTS catalog_repair_batch_evidence_idx ON import_batch_items(business_id,payload_hash,client_import_id)');
        $this->hasTracker = true;
    }
}
