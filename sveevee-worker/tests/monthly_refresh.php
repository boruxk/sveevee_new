<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Console\Application;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Pipeline\FullSnapshotRefresh;
use Sveevee\Worker\Research\Foursquare\DatasetPreparer as FoursquarePreparer;
use Sveevee\Worker\Research\Foursquare\PlaceMapper as FoursquareMapper;
use Sveevee\Worker\Research\Foursquare\PlacesSource;
use Sveevee\Worker\Research\Overture\DatasetPreparer as OverturePreparer;
use Sveevee\Worker\Research\Overture\PlaceMapper as OvertureMapper;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class MonthlyHttp implements HttpClientInterface
{
    public int $calls = 0;

    public int $status = 200;

    public function __construct(public readonly string $provider, public array $version) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $this->calls++;
        if ($this->provider === 'overture_places') {
            if ($url !== 'https://stac.overturemaps.org/catalog.json') {
                throw new RuntimeException('Unexpected Overture test request.');
            }
            $body = ['latest' => $this->version['release']];
        } else {
            if ($url !== FoursquarePreparer::ENDPOINT.'/v1/places/namespaces/datasets/tables/places_os') {
                throw new RuntimeException('Unexpected Foursquare test request.');
            }
            $body = ['metadata' => ['current-snapshot-id' => $this->version['snapshot_id'],
                'snapshots' => [['snapshot-id' => $this->version['snapshot_id'], 'timestamp-ms' => strtotime($this->version['release'].' UTC') * 1000]]]];
        }

        return new HttpResponse($this->status, [], Json::encode($body));
    }
}

final class MonthlyFixture
{
    public readonly WorkerConfig $config;

    public readonly WorkerRepository $repository;

    public readonly Database $database;

    public readonly MonthlyHttp $http;

    public readonly array $paths;

    public readonly string $snapshot;

    public readonly array $data;

    public int $prepares = 0;

    public bool $failPrepare = false;

    public function __construct(public readonly string $directory, public readonly string $provider)
    {
        mkdir($directory, 0700, true);
        $short = $provider === 'overture_places' ? 'overture' : 'foursquare';
        $this->snapshot = $directory.'/snapshot.sqlite';
        $data = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/worker.'.$short.'.json'));
        $data['sources'][$provider]['database_path'] = $this->snapshot;
        $this->data = $data;
        file_put_contents($directory.'/config.json', Json::encode($data));
        $this->config = WorkerConfig::load($directory.'/config.json', dirname(__DIR__));
        $this->database = new Database($directory.'/worker.sqlite');
        $this->repository = new WorkerRepository($this->database, new BusinessNormalizer(new OpeningHoursParser, $data['cities']), new BusinessMerger, [$provider]);
        $this->paths = ['database' => $directory.'/worker.sqlite', 'lock' => $directory.'/worker.lock'];
        $version = $provider === 'overture_places' ? ['release' => '2026-08-19.0'] : ['release' => '2026-08-11', 'snapshot_id' => '100'];
        $this->http = new MonthlyHttp($provider, $version);
        $this->writeSnapshot($version);
        $this->database->pdo->exec("INSERT INTO import_batches(client_import_id,payload_hash,request_json,status,response_json,created_at,updated_at) VALUES('historical','old','{}','completed','{}','old','old')");
    }

    public function writeSnapshot(array $version): void
    {
        $rows = [];
        foreach ([1, 2, 3] as $number) {
            $rows[] = $this->provider === 'overture_places'
                ? ['id' => sprintf('00000000-0000-4000-8000-%012d', $number), 'names' => ['primary' => 'Snapshot fixture '.$number],
                    'addresses' => [['country' => 'IL', 'locality' => 'Haifa', 'freeform' => 'Road '.$number]],
                    'taxonomy' => ['primary' => 'bakery'], 'confidence' => 1.0, 'operating_status' => null, 'sources' => [],
                    'bbox' => ['xmin' => 35, 'xmax' => 35, 'ymin' => 32, 'ymax' => 32]]
                : ['fsq_place_id' => str_pad((string) $number, 24, '0', STR_PAD_LEFT), 'name' => 'Snapshot fixture '.$number,
                    'country' => 'IL', 'locality' => 'Haifa', 'address' => 'Road '.$number, 'fsq_category_ids' => [], 'fsq_category_labels' => [], 'date_closed' => null];
        }
        $jsonl = $this->directory.'/input.jsonl';
        file_put_contents($jsonl, implode('', array_map(static fn (array $row): string => Json::encode($row)."\n", $rows)));
        if ($this->provider === 'overture_places') {
            (new OverturePreparer(OvertureMapper::fromConfig($this->data)))->importJsonl($jsonl, $version['release'], $this->snapshot, 3);
        } else {
            (new FoursquarePreparer(FoursquareMapper::fromConfig($this->data)))->importJsonl($jsonl, $version['release'], $this->snapshot, 3, $version['snapshot_id']);
        }
    }

    public function service(): FullSnapshotRefresh
    {
        return new FullSnapshotRefresh($this->config, $this->repository, $this->paths, $this->http,
            function (string $destination, array $version): void {
                $this->prepares++;
                if ($destination !== $this->snapshot || $this->failPrepare) {
                    throw new RuntimeException('Fixture preparation failed before publication.');
                }
                $this->writeSnapshot($version);
            });
    }

    public function advance(int $count = 3): void
    {
        $config = $this->config->source($this->provider);
        $reader = $this->provider === 'overture_places'
            ? new OverturePlacesSource($config, dirname(__DIR__), $this->repository)
            : new PlacesSource($config, dirname(__DIR__), $this->repository);
        $target = $this->provider === 'overture_places' ? ResearchTarget::overtureAll() : ResearchTarget::sourceAll($this->provider);
        foreach ($reader->research($target, $count) as $row) {
            $reader->acknowledge($row);
        }
    }

    public function nextVersion(): void
    {
        $this->http->version = $this->provider === 'overture_places' ? ['release' => '2026-09-16.0'] : ['release' => '2026-09-16', 'snapshot_id' => '200'];
    }

    public function business(?string $provider, string $status = 'pending'): int
    {
        $this->database->pdo->prepare('INSERT INTO businesses(payload_json,payload_hash,status,first_seen_at,last_seen_at) VALUES(?,?,?,?,?)')
            ->execute(['{}', 'fixture', $status, 'old', 'old']);
        $id = (int) $this->database->pdo->lastInsertId();
        if ($provider !== null) {
            $this->database->pdo->prepare('INSERT INTO business_sources(business_id,adapter,source_name,source_url,source_checked_at,raw_hash,raw_json) VALUES(?,?,?,?,?,?,?)')
                ->execute([$id, $provider, 'Fixture', 'https://fixture.example.org/'.$id, 'old', 'fixture', '{}']);
        }

        return $id;
    }
}

$root = sys_get_temp_dir().'/sveevee-monthly-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$sequence = 0;
$fixture = static fn (string $provider): MonthlyFixture => new MonthlyFixture($root.'/'.bin2hex(random_bytes(6)), $provider);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $action) use ($assert): void {
    try {
        $action();
    } catch (Throwable) {
        $assert(true, 'Unsafe action failed.');

        return;
    }
    $assert(false, 'Expected failure.');
};
$oldToken = getenv('FOURSQUARE_ACCESS_TOKEN');
putenv('FOURSQUARE_ACCESS_TOKEN=isolated-fixture-token');
$tests = [];
$tests['both providers check upstream but retain an identical complete snapshot and cursor'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        $f = $fixture($provider);
        $f->advance();
        $before = hash_file('sha256', $f->snapshot);
        $cursor = $f->database->pdo->query('SELECT * FROM source_scan_progress')->fetchAll();
        $service = $f->service();
        $assert($service->refresh('unused')['status'] === 'unchanged' && $f->http->calls === 1 && $f->prepares === 0, 'Only metadata lookup is needed for an unchanged upstream version.');
        $assert($before === hash_file('sha256', $f->snapshot) && $cursor === $f->database->pdo->query('SELECT * FROM source_scan_progress')->fetchAll(), 'Same-version refresh must not rewrite prepared_at or reset its cursor.');
        $assert(! $service->updateContinuation() && ! is_file(FullSnapshotRefresh::marker($f->paths)), 'An exhausted snapshot must not activate hourly import work.');
    }
};
$tests['a new version publishes immediately and creates continuation work without a 9000 minimum'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        $f = $fixture($provider);
        $f->advance();
        $f->nextVersion();
        $oldCursor = $f->database->pdo->query('SELECT * FROM source_scan_progress')->fetchAll();
        $batch = $f->database->pdo->query('SELECT * FROM import_batches')->fetchAll();
        $service = $f->service();
        $assert($service->refresh('unused')['status'] === 'refreshed' && $f->prepares === 1, 'New upstream version must be prepared even with only three records.');
        $assert($oldCursor === $f->database->pdo->query('SELECT * FROM source_scan_progress')->fetchAll() && $batch === $f->database->pdo->query('SELECT * FROM import_batches')->fetchAll(), 'Existing cursor history and batch UUIDs must survive publication.');
        $assert($service->updateContinuation(false) && is_file(FullSnapshotRefresh::marker($f->paths)), 'The new snapshot remainder must enable conditional continuation.');
        $f->advance(1);
        $assert($service->updateContinuation(), 'Any unfinished remainder, including two records, keeps continuation active.');
        $f->advance();
        $assert(! $service->updateContinuation() && ! is_file(FullSnapshotRefresh::marker($f->paths)) && $f->http->calls === 1, 'EOF removes continuation without further upstream requests.');
    }
};
$tests['newer versions defer behind existing work and are retried once after its EOF'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        $f = $fixture($provider);
        $f->advance(1);
        $f->nextVersion();
        $before = hash_file('sha256', $f->snapshot);
        $service = $f->service();
        $assert($service->refresh('unused')['status'] === 'deferred' && $f->prepares === 0 && $before === hash_file('sha256', $f->snapshot), 'Never discard an unfinished older source tail.');
        $service->updateContinuation(true);
        $assert(! $service->deferredRefreshReady() && $f->http->calls === 1, 'Hourly continuation must not repeatedly query upstream while old work remains.');
        $f->advance();
        $assert($service->updateContinuation() && $service->deferredRefreshReady(), 'Deferred newer release must remain scheduled after old EOF.');
        $assert($service->refresh('unused')['status'] === 'refreshed' && $f->http->calls === 2, 'One deferred metadata check must publish the new version.');
        $service->updateContinuation(false);
        $f->advance();
        $assert(! $service->updateContinuation(), 'The deferred flag must clear after successful new-version publication and processing.');
    }
};
$tests['metadata or preparation failure preserves the current snapshot and completed work'] = static function () use ($fixture, $assert, $throws): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        $f = $fixture($provider);
        $f->advance();
        $f->nextVersion();
        $before = hash_file('sha256', $f->snapshot);
        $f->http->status = 503;
        $throws(static fn () => $f->service()->refresh('unused'));
        $f->http->status = 200;
        $f->failPrepare = true;
        $throws(static fn () => $f->service()->refresh('unused'));
        $assert($before === hash_file('sha256', $f->snapshot) && ! is_file(FullSnapshotRefresh::marker($f->paths)), 'Failed refresh must not use a half-written snapshot or schedule replacement work.');
    }
};
$tests['pending retries keep continuation while terminal review and closed states do not'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        $f = $fixture($provider);
        $f->advance();
        $id = $f->business($provider);
        $service = $f->service();
        $assert($service->updateContinuation(), 'A matching pending business needs continuation after source EOF.');
        $f->database->pdo->exec("UPDATE businesses SET status='queued'");
        $f->database->pdo->exec("UPDATE import_batches SET status='pending'");
        $f->database->pdo->prepare('INSERT INTO import_batch_items(client_import_id,position,business_id) VALUES(?,?,?)')->execute(['historical', 1, $id]);
        $assert($service->updateContinuation(), 'An existing matching pending batch needs bounded continuation after source EOF.');
        $f->nextVersion();
        $assert($service->refresh('unused')['status'] === 'deferred', 'A matching pending original UUID must complete before snapshot replacement.');
        $f->database->pdo->exec("UPDATE import_batches SET status='completed'");
        foreach (['review', 'closed', 'claimed', 'failed'] as $status) {
            $f->business($provider, $status);
        }
        $assert(! $service->updateContinuation(false), 'Terminal decisions must not produce endless hourly retries.');
    }
};
$tests['foreign and orphan pending records and batches neither block refresh nor keep continuation active'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        $f = $fixture($provider);
        $service = $f->service();
        $service->updateContinuation();
        $f->advance();
        $foreign = $provider === 'overture_places' ? 'foursquare_places' : 'overture_places';
        $foreignId = $f->business($foreign);
        $orphanId = $f->business(null);
        $f->database->pdo->exec("UPDATE import_batches SET status='pending'");
        $assert(! $service->updateContinuation() && ! is_file(FullSnapshotRefresh::marker($f->paths)), 'Foreign pending businesses, source-less businesses and an empty historical batch must not retain the EOF marker.');
        $f->database->pdo->prepare('INSERT INTO import_batch_items(client_import_id,position,business_id) VALUES(?,?,?)')->execute(['historical', 1, $foreignId]);
        $f->database->pdo->prepare('INSERT INTO import_batch_items(client_import_id,position,business_id) VALUES(?,?,?)')->execute(['historical', 2, $orphanId]);
        $matchingId = $f->business($provider, 'queued');
        $f->database->pdo->prepare('INSERT INTO import_batch_items(client_import_id,position,business_id) VALUES(?,?,?)')->execute(['historical', 3, $matchingId]);
        $before = $f->database->pdo->query('SELECT * FROM import_batches')->fetchAll();
        $items = $f->database->pdo->query('SELECT * FROM import_batch_items')->fetchAll();
        $assert(! $service->updateContinuation(), 'One matching item cannot make a mixed-source batch resumable by this job.');
        $unscoped = new WorkerRepository($f->database, new BusinessNormalizer(new OpeningHoursParser, $f->data['cities']), new BusinessMerger);
        $assert(! (new FullSnapshotRefresh($f->config, $unscoped, $f->paths))->updateContinuation(), 'The full-source helper must reject foreign batches even when its caller supplies an unscoped repository.');
        $f->nextVersion();
        $assert($service->refresh('unused')['status'] === 'refreshed', 'Foreign and orphan work must not block a new source release.');
        $assert($before === $f->database->pdo->query('SELECT * FROM import_batches')->fetchAll()
            && $items === $f->database->pdo->query('SELECT * FROM import_batch_items')->fetchAll(), 'Unserviceable original batch evidence must remain untouched for review.');
    }
};
$tests['monthly and hourly jobs share a persisted calendar-hour slot without scheduler jitter'] = static function () use ($fixture, $assert): void {
    $f = $fixture('foursquare_places');
    $service = $f->service();
    $service->updateContinuation();
    $assert($service->canStartImport(), 'Initial pending work is ready immediately without a minimum record count.');
    $service->markImportStarted();
    $assert(! $f->service()->canStartImport(), 'A fresh helper/process must enforce the recorded hour interval.');
    $service->updateContinuation(true);
    $assert(! $service->canStartImport(), 'A newer release or marker update must not reset the hour interval.');
    $path = FullSnapshotRefresh::marker($f->paths);
    $marker = Json::decode((string) file_get_contents($path));
    $assert($marker['next_import_at'] === (intdiv(time(), 3600) + 1) * 3600, 'Next scheduled hour must remain eligible despite seconds of scheduler jitter.');
    $marker['next_import_at'] = time() - 1;
    file_put_contents($path, Json::encode($marker));
    $assert($f->service()->canStartImport(), 'Work becomes eligible after the persisted start interval.');
};
$tests['CLI separates refresh from conditional continuation and timer units preserve 9000 cap'] = static function () use ($assert, $throws): void {
    $app = new Application(dirname(__DIR__));
    $parse = new ReflectionMethod($app, 'parse');
    [, $options] = $parse->invoke($app, ['worker', 'run', '--refresh', '--duckdb=/fixture/duckdb']);
    $assert($options['refresh'] && $options['duckdb'] === '/fixture/duckdb', 'Monthly CLI must support the installed DuckDB path.');
    [, $options] = $parse->invoke($app, ['worker', 'run', '--continue-snapshot', '--duckdb=/fixture/duckdb']);
    $assert($options['continue_snapshot'], 'Hourly continuation must be explicit.');
    $throws(static fn () => $parse->invoke($app, ['worker', 'run', '--refresh', '--continue-snapshot']));
    $throws(static fn () => $parse->invoke($app, ['worker', 'run', '--refresh', '--dry-run']));
    $throws(static fn () => $parse->invoke($app, ['worker', 'run', '--continue-snapshot', '--dry-run']));
    $throws(static fn () => $parse->invoke($app, ['worker', 'import', '--refresh']));
    foreach (['overture', 'foursquare'] as $provider) {
        $service = file_get_contents(dirname(__DIR__).'/deploy/systemd/sveevee-'.$provider.'.service');
        $continuation = file_get_contents(dirname(__DIR__).'/deploy/systemd/sveevee-'.$provider.'-continue.service');
        $assert(str_contains($service, '--refresh --duckdb=/usr/local/bin/duckdb --limit=9000'), 'Monthly service must actually check upstream before importing.');
        $assert(str_contains($continuation, '--continue-snapshot') && str_contains($continuation, '--limit=9000') && str_contains($continuation, 'ConditionPathExists=/var/lib/sveevee-worker/'.$provider.'/snapshot-import.pending.json'), 'No unconditional hourly import is allowed after EOF.');
    }
};
$tests['real CLI skips inactive and early continuations and immediately attempts a refresh failure report'] = static function () use ($fixture, $assert): void {
    $f = $fixture('foursquare_places');
    $directory = $f->directory.'/cli-state';
    $environment = getenv();
    unset($environment['FOURSQUARE_ACCESS_TOKEN']);
    $environment['SVEVEE_WORKER_DATA_DIR'] = $directory;
    $environment['SVEVEE_API_URL'] = 'https://fixture.example.org/api/v1/business-import';
    $environment['SVEVEE_TOKEN_URL'] = 'https://fixture.example.org/token';
    $environment['SVEVEE_CLIENT_ID'] = 'fixture-client';
    $environment['SVEVEE_CLIENT_SECRET'] = 'fixture-secret';
    file_put_contents($f->directory.'/empty.env', '');
    $run = static function (string $mode) use ($f, $environment): int {
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec',
            dirname(__DIR__).'/bin/worker', 'run', '--config='.$f->directory.'/config.json',
            '--env-file='.$f->directory.'/empty.env', $mode],
            [1 => ['file', $f->directory.'/cli.out', 'w'], 2 => ['file', $f->directory.'/cli.err', 'w']], $pipes, dirname(__DIR__), $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start isolated monthly CLI test.');
        }

        return proc_close($process);
    };
    $assert($run('--continue-snapshot') === 0 && ! is_file($directory.'/foursquare/worker.sqlite'), 'No marker means no worker state, report or network work.');
    mkdir($directory.'/foursquare', 0700, true);
    file_put_contents($directory.'/foursquare/snapshot-import.pending.json', Json::encode(['provider' => 'foursquare_places', 'next_import_at' => time() + 7200]));
    $assert($run('--continue-snapshot') === 0, 'An early continuation must return without API work.');
    $db = new PDO('sqlite:'.$directory.'/foursquare/worker.sqlite');
    $assert((int) $db->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 0, 'An early trigger must not create a misleading empty import report.');
    $assert($run('--refresh') === 1, 'Missing download credentials must fail the monthly check.');
    $record = $db->query('SELECT payload_json,attempts,reported_at FROM run_log_outbox')->fetch(PDO::FETCH_ASSOC);
    $report = Json::decode($record['payload_json']);
    $assert($record['attempts'] === 1 && $record['reported_at'] === null && $report['status'] === 'failed'
        && str_contains($report['errors'][0]['message'], 'FOURSQUARE_ACCESS_TOKEN'), 'Refresh failure reporting must be attempted immediately and retained for retry when HTTP is deliberately disabled.');
    $assert((int) $db->query('SELECT COUNT(*) FROM import_batches')->fetchColumn() === 0, 'A failed refresh must not import stale records.');
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL);
        }
    }
} finally {
    putenv($oldToken === false ? 'FOURSQUARE_ACCESS_TOKEN' : 'FOURSQUARE_ACCESS_TOKEN='.$oldToken);
    gc_collect_cycles();
    $resolved = realpath($root);
    if ($resolved === false || realpath(dirname($resolved)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'sveevee-monthly-')) {
        throw new RuntimeException('Unexpected test cleanup path.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $parent = realpath(dirname($file->getPathname()));
        if ($parent === false || ($parent !== $resolved && ! str_starts_with($parent, $resolved.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Unexpected test child path.');
        }
        $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    unset($files);
    rmdir($resolved);
    restore_error_handler();
}
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
