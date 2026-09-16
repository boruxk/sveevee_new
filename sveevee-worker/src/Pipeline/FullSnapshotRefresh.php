<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Closure;
use PDO;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Http\CurlHttpClient;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Research\Foursquare\DatasetPreparer as FoursquarePreparer;
use Sveevee\Worker\Research\Foursquare\PlaceMapper as FoursquareMapper;
use Sveevee\Worker\Research\Foursquare\PlacesSource;
use Sveevee\Worker\Research\Overture\DatasetPreparer as OverturePreparer;
use Sveevee\Worker\Research\Overture\PlaceMapper as OvertureMapper;
use Sveevee\Worker\Research\Overture\ReleaseCatalog;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;

/** Caller holds the job's ProcessLock across refresh, import and continuation updates. */
final class FullSnapshotRefresh
{
    private readonly string $provider;

    private readonly string $snapshot;

    public function __construct(
        private readonly WorkerConfig $config,
        private readonly WorkerRepository $repository,
        private readonly array $paths,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?Closure $prepareOverride = null,
    ) {
        $this->provider = $config->fullSourceProvider() ?? '';
        if (! in_array($this->provider, ['overture_places', 'foursquare_places'], true)) {
            throw new RuntimeException('Snapshot refresh requires the dedicated full-source Overture or Foursquare config.');
        }
        $configured = trim((string) ($config->source($this->provider)['database_path'] ?? ''));
        $this->snapshot = $configured !== '' ? $config->resolvePath($configured)
            : dirname($paths['database']).'/'.($this->provider === 'overture_places' ? 'overture' : 'foursquare').'.sqlite';
    }

    public function refresh(string $duckdb): array
    {
        $before = $this->current();
        $http = $this->http ?? new CurlHttpClient;
        $mapping = ['cities' => $this->config->get('cities', []), 'sources' => $this->config->get('sources', [])];
        $catalog = $this->provider === 'overture_places' ? new ReleaseCatalog($http) : null;
        $foursquare = $this->provider === 'foursquare_places' ? new FoursquarePreparer(FoursquareMapper::fromConfig($mapping), $http) : null;
        $token = $this->provider === 'foursquare_places' ? Environment::require('FOURSQUARE_ACCESS_TOKEN') : null;
        $latest = $catalog !== null ? ['release' => $catalog->latest()] : $foursquare->snapshot($token);
        $field = $this->provider === 'overture_places' ? 'release' : 'snapshot_id';
        $result = ['provider' => $this->provider, 'status' => 'unchanged', 'upstream_release' => $latest['release'],
            'current_release' => $before['metadata']['release'] ?? null];
        if ($before !== null && ($before['metadata'][$field] ?? null) === $latest[$field]) {
            return $result;
        }
        if ($before !== null && ($before['progress']['remaining'] > 0 || $this->hasPending())) {
            return [...$result, 'status' => 'deferred', 'reason' => 'finish_current_snapshot', 'remaining' => $before['progress']['remaining']];
        }
        if ($before !== null && version_compare($latest['release'], $before['metadata']['release'], '<')) {
            throw new RuntimeException('The upstream snapshot is older than the current complete snapshot; it was retained.');
        }
        if ($this->prepareOverride !== null) {
            ($this->prepareOverride)($this->snapshot, $latest);
        } elseif ($catalog !== null) {
            (new OverturePreparer(OvertureMapper::fromConfig($mapping)))->prepare(
                $duckdb, $catalog->files($latest['release']), $latest['release'], $this->snapshot,
                (float) ($this->config->source($this->provider)['min_confidence'] ?? 0.0),
            );
        } else {
            $foursquare->prepare($duckdb, $token, $this->snapshot, $latest);
        }
        $after = $this->current();
        if ($after === null || ($after['metadata'][$field] ?? null) !== $latest[$field]) {
            throw new RuntimeException('Prepared snapshot does not match the checked upstream version.');
        }

        return [...$result, 'status' => 'refreshed', 'current_release' => $after['metadata']['release']];
    }

    public static function marker(array $paths): string
    {
        return dirname($paths['database']).'/snapshot-import.pending.json';
    }

    /** Local progress controls hourly work; reviewed, claimed, closed and terminal failures are not queued. */
    public function updateContinuation(?bool $refreshPending = null, ?int $nextImportAt = null): bool
    {
        $current = $this->current();
        $refreshPending ??= $this->deferredRefresh();
        $active = $refreshPending || ($current !== null && $current['progress']['remaining'] > 0) || $this->hasPending();
        $path = self::marker($this->paths);
        $nextImportAt ??= $this->continuation()['next_import_at'] ?? 0;
        if (! $active) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Cannot clear the completed snapshot continuation marker.');
            }

            return false;
        }
        $stage = $path.'.stage-'.bin2hex(random_bytes(6));
        try {
            $json = Json::encode(['provider' => $this->provider, 'release' => $current['metadata']['release'] ?? null,
                'refresh_pending' => $refreshPending, 'next_import_at' => $nextImportAt, 'updated_at' => Clock::now()]);
            if (file_put_contents($stage, $json) !== strlen($json) || ! rename($stage, $path)) {
                throw new RuntimeException('Cannot persist the snapshot continuation marker.');
            }
        } finally {
            if (is_file($stage)) {
                unlink($stage);
            }
        }

        return true;
    }

    public function deferredRefreshReady(): bool
    {
        $current = $this->current();

        return $this->deferredRefresh() && ($current === null || $current['progress']['remaining'] === 0) && ! $this->hasPending();
    }

    public function canStartImport(): bool
    {
        return ($this->continuation()['next_import_at'] ?? 0) <= time();
    }

    public function markImportStarted(): void
    {
        // Fixed minute timers use calendar-hour slots: seconds of scheduler jitter must not skip the next hour.
        $this->updateContinuation(nextImportAt: (intdiv(time(), 3600) + 1) * 3600);
    }

    private function deferredRefresh(): bool
    {
        return ($this->continuation()['refresh_pending'] ?? false) === true;
    }

    private function continuation(): array
    {
        $path = self::marker($this->paths);
        if (! is_file($path)) {
            return [];
        }
        $marker = Json::decode((string) file_get_contents($path));
        if (($marker['provider'] ?? null) !== $this->provider
            || (isset($marker['next_import_at']) && (! is_int($marker['next_import_at']) || $marker['next_import_at'] < 0))) {
            throw new RuntimeException('The continuation marker belongs to another source.');
        }

        return $marker;
    }

    private function hasPending(): bool
    {
        $target = $this->provider === 'overture_places'
            ? ResearchTarget::overtureAll() : ResearchTarget::sourceAll($this->provider);
        foreach ($this->repository->pendingForTarget($target) as $_business) {
            return true;
        }
        foreach ($this->repository->pendingBatches() as $batch) {
            if ($batch['items'] === []) {
                continue;
            }
            foreach ($batch['items'] as $item) {
                if (! $this->repository->acceptsBusinessSource((int) $item['business_id'])
                    || ! $this->repository->hasSource((int) $item['business_id'], $this->provider)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function current(): ?array
    {
        if (! is_file($this->snapshot)) {
            return null;
        }
        $sourceConfig = [...$this->config->source($this->provider), 'database_path' => $this->snapshot];
        $reader = $this->provider === 'overture_places'
            ? new OverturePlacesSource($sourceConfig, $this->config->root, $this->repository)
            : new PlacesSource($sourceConfig, $this->config->root, $this->repository);
        $progress = $reader->progress();
        $database = new PDO('sqlite:'.$this->snapshot, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $metadata = $database->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);

        return ['metadata' => $metadata, 'progress' => $progress];
    }
}
