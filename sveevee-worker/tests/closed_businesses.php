<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\ClosedBusinessGateway;
use Sveevee\Worker\Api\OAuthTokenProvider;
use Sveevee\Worker\Api\SveeveeApiClient;
use Sveevee\Worker\Console\Application;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Pipeline\ClosedBusinessRemovalService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\Foursquare\DatasetPreparer;
use Sveevee\Worker\Research\Foursquare\PlaceMapper;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class ClosureFixtureGateway implements ClosedBusinessGateway
{
    public array $requests = [];

    public array $removed = [];

    public array $statuses = [];

    public ?int $failCall = null;

    public bool $corruptResponse = false;

    public function removeClosedBusinesses(array $request): array
    {
        $this->requests[] = $request;
        if (count($this->requests) === $this->failCall) {
            throw new ApiException('Fixture unavailable.', 503, 'unavailable', retryable: true);
        }
        $items = [];
        foreach ($request['businesses'] as $business) {
            $id = $business['source_id'];
            $status = $this->statuses[$id] ?? (isset($this->removed[$id]) ? 'already_removed' : ($request['dry_run'] ? 'would_remove' : 'removed'));
            if ($status === 'removed') {
                $this->removed[$id] = true;
            }
            $items[] = ['source_id' => $id, 'status' => $status];
        }
        if ($this->corruptResponse) {
            $items[0]['source_id'] = str_repeat('f', 24);
        }

        return ['snapshot_id' => $request['snapshot_id'], 'dry_run' => $request['dry_run'], 'items' => $items];
    }
}

$directory = sys_get_temp_dir().'/sveevee-closure-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $action, string $message) use ($assert): void {
    try {
        $action();
    } catch (Throwable) {
        $assert(true, $message);

        return;
    }
    $assert(false, $message);
};
$id = static fn (int $number): string => str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
$fixture = static function (string $name, int $closed, int $open = 0) use ($directory, $id): string {
    $jsonl = $directory.'/'.$name.'.jsonl';
    $rows = [];
    for ($number = 1; $number <= $closed + $open; $number++) {
        $rows[] = Json::encode(['fsq_place_id' => $id($number), 'name' => 'Fixture place '.$number,
            'country' => 'IL', 'locality' => 'Unmapped locality', 'address' => 'Original road '.$number,
            'fsq_category_ids' => [], 'fsq_category_labels' => [],
            'date_closed' => $number <= $closed ? '2025-01-02' : null])."\n";
    }
    file_put_contents($jsonl, implode('', $rows));
    $snapshot = $directory.'/'.$name.'.sqlite';
    (new DatasetPreparer(new PlaceMapper))->importJsonl($jsonl, '2026-08-11', $snapshot, count($rows), '6981762386080966939');

    return $snapshot;
};
$report = static fn (bool $dryRun): RunReport => new RunReport(Uuid::v4(), 'remove-closed-businesses', $dryRun);
$tests = [];

$tests['dry-run scans the complete real prepared snapshot and sends only closure evidence in bounded batches'] = static function () use ($fixture, $report, $assert): void {
    $path = $fixture('dry-run', 205, 3);
    $hash = hash_file('sha256', $path);
    $api = new ClosureFixtureGateway;
    $runReport = $report(true);
    $progress = (new ClosedBusinessRemovalService($api))->run($path, true, $runReport);
    $assert(array_map(static fn (array $request): int => count($request['businesses']), $api->requests) === [100, 100, 5], 'Closure API batches must be bounded at100.');
    $assert($progress['total'] === 208 && $progress['scanned'] === 208 && $progress['closed'] === 205, 'Open records must be scanned without being removal candidates.');
    $assert($progress['would_remove'] === 205 && $progress['removed'] === 0 && $api->removed === [], 'Dry-run must not remove pages.');
    $assert(count(array_unique(array_column(array_merge(...array_column($api->requests, 'businesses')), 'source_id'))) === 205, 'Each source ID must be sent once.');
    $first = $api->requests[0]['businesses'][0];
    $assert($first['date_closed'] === '2025-01-02' && $first['country'] === 'IL'
        && $first['address'] === ['city' => 'Unmapped locality', 'street' => 'Original road 1'], 'Preserve source location evidence without guessing a catalog city.');
    $array = $runReport->toArray();
    $assert($array['command'] === 'remove-closed-businesses' && $array['imported'] === 0
        && $array['closed_businesses_progress'] === $progress && $array['source_counts'] === ['foursquare_places' => 205], 'Publish separate closure metrics, not new import successes.');
    $assert(hash_file('sha256', $path) === $hash, 'Closure scans must not change the prepared snapshot.');
};

$tests['claimed, ambiguous and unmatched results remain separate and applied repeats are idempotent'] = static function () use ($fixture, $report, $assert, $id): void {
    $path = $fixture('statuses', 5);
    $api = new ClosureFixtureGateway;
    $api->statuses = [$id(1) => 'protected_claimed', $id(2) => 'review_required', $id(3) => 'unmatched'];
    $runReport = $report(false);
    $first = (new ClosedBusinessRemovalService($api))->run($path, false, $runReport);
    $assert($first['removed'] === 2 && $first['protected_claimed'] === 1 && $first['review_required'] === 1 && $first['unmatched'] === 1, 'Status counters must preserve owner and identity protections.');
    $assert($runReport->metric('review') === 2, 'Overall review must include protected-owner and ambiguous cases while preserving their separate counters.');
    $second = (new ClosedBusinessRemovalService($api))->run($path, false, $report(false));
    $assert($second['removed'] === 0 && $second['already_removed'] === 2 && count($api->removed) === 2, 'Retry must retain the same source/snapshot identity.');
    $assert($api->requests[0] === $api->requests[1], 'Applied retry must send exactly the original evidence.');
};

$tests['invalid closure evidence is reviewed without transmission while unusable names alone do not imply closure'] = static function () use ($fixture, $report, $assert, $id): void {
    $path = $fixture('invalid-evidence', 10, 1);
    $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $mutations = [
        1 => static function (array &$data): void {
            $data['date_closed'] = '2026-02-31';
        },
        2 => static function (array &$data): void {
            $data['date_closed'] = '2026-09-01';
        },
        3 => static function (array &$data): void {
            $data['date_closed'] = '1899-12-31';
        },
        4 => static function (array &$data): void {
            $data['country'] = 'US';
        },
        5 => static function (array &$data): void {
            $data['source_id'] = str_repeat('f', 24);
        },
        6 => static function (array &$data): void {
            $data['original_record']['date_closed'] = null;
        },
        7 => static function (array &$data): void {
            $data['date_closed'] = ['invalid'];
        },
        10 => static function (array &$data): void {
            $data['preparation_error'] = 'invalid_business_name';
        },
        11 => static function (array &$data): void {
            $data['preparation_error'] = 'invalid_business_name';
        },
    ];
    foreach ($mutations as $number => $mutate) {
        $read = $db->prepare('SELECT source_metadata FROM places WHERE id=?');
        $read->execute([$id($number)]);
        $data = Json::decode($read->fetchColumn());
        $mutate($data);
        $db->prepare('UPDATE places SET source_metadata=? WHERE id=?')->execute([Json::encode($data), $id($number)]);
    }
    $db->prepare('UPDATE places SET source_url=? WHERE id=?')->execute(['https://example.org/unverified', $id(8)]);
    $db->prepare('UPDATE places SET city=? WHERE id=?')->execute([str_repeat('x', 121), $id(9)]);
    $db = null;
    $api = new ClosureFixtureGateway;
    $runReport = $report(true);
    $progress = (new ClosedBusinessRemovalService($api))->run($path, true, $runReport);
    $assert($progress['closed'] === 10 && $progress['invalid_evidence'] === 9 && $progress['would_remove'] === 1, 'Only validated date, provenance and location evidence may leave the worker.');
    $assert(array_column($api->requests[0]['businesses'], 'source_id') === [$id(10)], 'Invalid name does not weaken valid closure evidence; open invalid names are not closed.');
    $assert($runReport->metric('review') === 9, 'Invalid evidence must remain visible as review.');
};

$tests['failed batch stops immediately and next invocation replays prior successes safely'] = static function () use ($fixture, $report, $assert, $throws): void {
    $path = $fixture('retry', 205);
    $api = new ClosureFixtureGateway;
    $api->failCall = 2;
    $runReport = $report(false);
    $throws(static fn () => (new ClosedBusinessRemovalService($api))->run($path, false, $runReport), 'An API outage must fail the closure command.');
    $progress = $runReport->toArray()['closed_businesses_progress'];
    $assert(count($api->requests) === 2 && $progress['scanned'] === 200 && $progress['removed'] === 100 && $progress['failed'] === 100, 'Stop after the failed batch and preserve completed metrics.');
    $api->failCall = null;
    $retry = (new ClosedBusinessRemovalService($api))->run($path, false, $report(false));
    $assert($retry['already_removed'] === 100 && $retry['removed'] === 105 && count($api->removed) === 205, 'Repeat must recover all remaining closures without replacing prior identity.');
};

$tests['closure evidence preserves a300 character source street for backend verification'] = static function () use ($fixture, $report, $assert): void {
    $path = $fixture('long-street', 1);
    $db = new PDO('sqlite:'.$path);
    $street = str_repeat('x', 300);
    $db->prepare('UPDATE places SET street=?')->execute([$street]);
    $db = null;
    $api = new ClosureFixtureGateway;
    $progress = (new ClosedBusinessRemovalService($api))->run($path, true, $report(true));
    $assert($progress['invalid_evidence'] === 0 && $progress['would_remove'] === 1, 'Long raw streets must not lose safe closure evidence.');
    $assert($api->requests[0]['businesses'][0]['address']['street'] === $street, 'Never truncate or omit a long address before the backend identity check.');
};

$tests['invalid snapshot metadata and inconsistent API responses stop before further removals'] = static function () use ($fixture, $report, $assert, $throws): void {
    $path = $fixture('invalid-snapshot', 2);
    $db = new PDO('sqlite:'.$path);
    $db->exec("UPDATE metadata SET value='2099-01-01' WHERE key='release'");
    $db = null;
    $api = new ClosureFixtureGateway;
    $throws(static fn () => (new ClosedBusinessRemovalService($api))->run($path, false, $report(false)), 'Future snapshots must not be used for deletion.');
    $assert($api->requests === [], 'Invalid global metadata must fail before any API request.');
    $path = $fixture('invalid-response', 3);
    $api->corruptResponse = true;
    $runReport = $report(true);
    $throws(static fn () => (new ClosedBusinessRemovalService($api, 2))->run($path, true, $runReport), 'Unrelated source IDs in a response must not be accepted.');
    $assert(count($api->requests) === 1 && $runReport->toArray()['closed_businesses_progress']['failed'] === 2, 'Invalid responses must stop subsequent batches.');
};

$tests['API client preserves dry-run and source evidence and cannot send oversized closure batches'] = static function () use ($assert, $throws, $id): void {
    $http = new class implements HttpClientInterface
    {
        public array $requests = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
        {
            $this->requests[] = compact('method', 'url', 'headers', 'body');
            if (str_ends_with($url, '/token')) {
                return new HttpResponse(200, [], Json::encode(['access_token' => 'fixture-token', 'expires_in' => 3600]));
            }
            $request = Json::decode($body);

            return new HttpResponse(200, [], Json::encode(['success' => true, 'data' => ['snapshot_id' => $request['snapshot_id'], 'dry_run' => $request['dry_run'], 'items' => []]]));
        }
    };
    $api = new SveeveeApiClient($http, new OAuthTokenProvider($http, 'https://fixture.example.org/token', 'fixture-client', 'fixture-secret', 5, 'ClosureTest'),
        'https://fixture.example.org/api/v1/business-import', 5, 0, 0, 'ClosureTest');
    $request = ['snapshot_id' => '123', 'release' => '2026-08-11', 'dry_run' => true,
        'businesses' => [['source_id' => $id(1), 'country' => 'IL', 'date_closed' => '2020-01-01']]];
    $api->removeClosedBusinesses($request);
    $assert(count($http->requests) === 2 && $http->requests[1]['url'] === 'https://fixture.example.org/api/v1/business-import/closed-businesses'
        && Json::decode($http->requests[1]['body']) === $request, 'The authenticated closure endpoint must receive exact evidence and dry-run flag.');
    $throws(static fn () => $api->removeClosedBusinesses(array_replace($request, ['businesses' => array_fill(0, 101, $request['businesses'][0])])), 'Oversized batches must fail locally.');
    $assert(count($http->requests) === 2, 'Rejected requests must not make an HTTP call.');
};

$tests['CLI requires explicit apply and monthly service refresh must succeed before deletion'] = static function () use ($assert, $throws): void {
    $app = new Application(dirname(__DIR__));
    $parse = new ReflectionMethod($app, 'parse');
    [$command, $options] = $parse->invoke($app, ['worker', 'remove-closed-businesses']);
    $assert($command === 'remove-closed-businesses' && ! ($options['apply'] ?? false), 'Closure command must default to dry-run.');
    [, $options] = $parse->invoke($app, ['worker', 'remove-closed-businesses', '--apply']);
    $assert($options['apply'] === true, 'Explicit apply must be recognized.');
    $throws(static fn () => $parse->invoke($app, ['worker', 'remove-closed-businesses', '--apply', '--dry-run']), 'Conflicting mutation modes must fail.');
    $throws(static fn () => $parse->invoke($app, ['worker', 'run', '--apply']), 'The closure flag must not change ordinary import behavior.');
    [, $refreshOptions] = $parse->invoke($app, ['worker', 'run', '--refresh']);
    $assert($refreshOptions['refresh'] === true, 'Dedicated full-source imports may request a version-aware refresh.');
    $throws(static fn () => $parse->invoke($app, ['worker', 'remove-closed-businesses', '--duckdb=fixture']), 'DuckDB has no effect without an explicit refresh.');
    $throws(static fn () => $parse->invoke($app, ['worker', 'remove-closed-businesses', '--limit=1']), 'Monthly checks must not silently stop at the ordinary10/9000 import cap.');
    $service = file_get_contents(dirname(__DIR__).'/deploy/systemd/sveevee-closed-businesses.service');
    $assert(! str_contains($service, 'ExecStartPre=')
        && str_contains($service, 'remove-closed-businesses --config=/etc/sveevee-worker/worker.foursquare.json --refresh --duckdb=/usr/local/bin/duckdb --apply'), 'Refresh and removal must share the same locked CLI invocation.');
    $assert(str_contains($service, 'EnvironmentFile=/etc/sveevee-worker/foursquare-download.env')
        && ! str_contains($service, 'FOURSQUARE_ACCESS_TOKEN='), 'The downloader secret must come from protected environment, not unit arguments.');
};

$tests['real CLI defaults to preview and persists a separate failed report before any network call'] = static function () use ($directory, $assert): void {
    $root = dirname(__DIR__);
    $config = Json::decode((string) file_get_contents($root.'/config/worker.foursquare.json'));
    $configPath = $directory.'/cli-config.json';
    file_put_contents($configPath, Json::encode($config));
    $environmentPath = $directory.'/empty.env';
    file_put_contents($environmentPath, '');
    $environment = getenv();
    foreach (['SVEVEE_API_URL', 'SVEEVEE_BUSINESS_IMPORT_API_URL', 'SVEVEE_TOKEN_URL', 'SVEEVEE_OAUTH_TOKEN_URL',
        'SVEVEE_CLIENT_ID', 'SVEEVEE_BUSINESS_IMPORT_CLIENT_ID', 'SVEVEE_CLIENT_SECRET', 'SVEEVEE_BUSINESS_IMPORT_CLIENT_SECRET'] as $key) {
        unset($environment[$key]);
    }
    $environment['SVEVEE_WORKER_DATA_DIR'] = $directory.'/cli-data';
    foreach ([true, false] as $dryRun) {
        $output = $directory.'/cli-'.(int) $dryRun.'.out';
        $error = $directory.'/cli-'.(int) $dryRun.'.err';
        $arguments = [PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec',
            $root.'/bin/worker', 'remove-closed-businesses', '--config='.$configPath, '--env-file='.$environmentPath];
        if (! $dryRun) {
            $arguments[] = '--apply';
        }
        $process = proc_open($arguments, [1 => ['file', $output, 'w'], 2 => ['file', $error, 'w']], $pipes, $root, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start isolated closure CLI test.');
        }
        $assert(proc_close($process) === 1, 'Missing credentials must fail the real CLI before HTTP.');
        $db = new PDO('sqlite:'.$directory.'/cli-data/foursquare/worker.sqlite');
        $query = $db->prepare('SELECT report_json FROM runs WHERE dry_run=?');
        $query->execute([(int) $dryRun]);
        $rows = $query->fetchAll(PDO::FETCH_COLUMN);
        $assert(count($rows) === 1, 'Each closure invocation must persist exactly one separate report.');
        $report = Json::decode($rows[0]);
        $assert($report['command'] === 'remove-closed-businesses' && $report['dry_run'] === $dryRun
            && $report['status'] === 'failed' && $report['used_sources'] === ['foursquare_places'], 'The CLI must preserve preview/apply mode and closure provenance in its report.');
        $assert($report['imported'] === 0 && $report['errors'][0]['stage'] === 'fatal'
            && str_contains($report['errors'][0]['message'], 'SVEVEE_API_URL'), 'No ordinary research or import path may run before the closure API is configured.');
        $assert((int) $db->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 0
            && (int) $db->query('SELECT COUNT(*) FROM import_batches')->fetchColumn() === 0
            && (int) $db->query('SELECT COUNT(*) FROM source_scan_progress')->fetchColumn() === 0, 'Closure commands must not create import candidates, batches or advance source cursors.');
        unset($query, $db);
    }
};

$tests['refresh failure never falls back to old closure data and the worker lock covers the refresh'] = static function () use ($directory, $fixture, $assert): void {
    $root = dirname(__DIR__);
    $snapshot = $fixture('refresh-old', 1);
    $baseline = hash_file('sha256', $snapshot);
    $config = Json::decode((string) file_get_contents($root.'/config/worker.foursquare.json'));
    $config['sources']['foursquare_places']['database_path'] = $snapshot;
    $config['api']['max_retries'] = 0;
    $configPath = $directory.'/refresh-config.json';
    file_put_contents($configPath, Json::encode($config));
    $environmentPath = $directory.'/refresh-empty.env';
    file_put_contents($environmentPath, '');
    $environment = getenv();
    unset($environment['FOURSQUARE_ACCESS_TOKEN']);
    $environment['SVEVEE_API_URL'] = 'https://fixture.example.org/api/v1/business-import';
    $environment['SVEVEE_TOKEN_URL'] = 'https://fixture.example.org/token';
    $environment['SVEVEE_CLIENT_ID'] = 'fixture-client';
    $environment['SVEVEE_CLIENT_SECRET'] = 'fixture-secret';
    $environment['SVEVEE_WORKER_DATA_DIR'] = $directory.'/refresh-data';
    $arguments = [PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec',
        $root.'/bin/worker', 'remove-closed-businesses', '--config='.$configPath, '--env-file='.$environmentPath, '--refresh', '--apply'];
    $execute = static function (string $suffix) use ($arguments, $directory, $root, $environment): array {
        $output = $directory.'/refresh-'.$suffix.'.out';
        $error = $directory.'/refresh-'.$suffix.'.err';
        $process = proc_open($arguments, [1 => ['file', $output, 'w'], 2 => ['file', $error, 'w']], $pipes, $root, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start isolated closure refresh test.');
        }

        return [proc_close($process), (string) file_get_contents($error)];
    };
    [$exit] = $execute('missing-token');
    $assert($exit === 1 && hash_file('sha256', $snapshot) === $baseline, 'Missing refresh credentials must retain the old snapshot without processing it.');
    $databasePath = $directory.'/refresh-data/foursquare/worker.sqlite';
    $db = new PDO('sqlite:'.$databasePath);
    $rows = $db->query('SELECT report_json FROM runs')->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($rows) === 1, 'A failed refresh must produce one normal worker report.');
    $report = Json::decode($rows[0]);
    $assert($report['status'] === 'failed' && $report['dry_run'] === false
        && str_contains($report['errors'][0]['message'], 'FOURSQUARE_ACCESS_TOKEN')
        && ! isset($report['closed_businesses_progress']), 'Refresh must fail before reading stale closure candidates.');
    $assert((int) $db->query('SELECT COUNT(*) FROM source_scan_progress')->fetchColumn() === 0
        && (int) $db->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 0, 'Refresh failures must not advance normal import state.');
    $lock = new ProcessLock($directory.'/refresh-data/foursquare/worker.lock');
    $lock->acquire();
    [$exit, $error] = $execute('locked');
    $assert($exit === 1 && str_contains($error, 'Another worker process is already running.'), 'The shared process lock must be acquired before any refresh.');
    $assert((int) $db->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 1
        && hash_file('sha256', $snapshot) === $baseline, 'A competing worker must prevent the entire refresh/removal run.');
    unset($lock, $db);
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
    gc_collect_cycles();
    $resolved = realpath($directory);
    if ($resolved === false || ! str_starts_with(basename($resolved), 'sveevee-closure-test-') || realpath(dirname($resolved)) !== realpath(sys_get_temp_dir())) {
        throw new RuntimeException('Unexpected fixture cleanup path.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $parent = realpath(dirname($file->getPathname()));
        if ($parent === false || ($parent !== $resolved && ! str_starts_with($parent, $resolved.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Unexpected fixture child cleanup path.');
        }
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    unset($files);
    rmdir($directory);
    restore_error_handler();
}
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
