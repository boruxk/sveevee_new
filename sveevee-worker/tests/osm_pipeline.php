<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\OpenStreetMap\PlaceMapper;
use Sveevee\Worker\Research\OpenStreetMap\PlacesSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

$directory = sys_get_temp_dir().'/sveevee-osm-pipeline-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) { throw new RuntimeException($message); }
};
$api = new class implements SveeveeGateway {
    public array $created = [];
    public int $checks = 0;
    public int $batches = 0;
    public bool $fail = true;
    public function checkDuplicate(array $business): array { $this->checks++; return ['matches' => []]; }
    public function searchBusinesses(array $filters): array { return ['businesses' => []]; }
    public function reportRun(array $report): array { return []; }
    public function importBatch(array $request): array {
        $this->batches++;
        if ($this->fail) { $this->fail = false; throw new ApiException('Offline fixture failure.', 503, 'unavailable', retryable: true); }
        if (count($request['businesses']) > 100) { throw new RuntimeException('OSM transport batch exceeded 100.'); }
        $items = [];
        foreach ($request['businesses'] as $index => $business) {
            $id = $business['source']['id'];
            if ($business['source']['provider'] !== 'osm_places' || isset($this->created[$id])) { throw new RuntimeException('Missing OSM source or duplicate creation.'); }
            $this->created[$id] = count($this->created) + 1;
            $items[] = ['position' => $index + 1, 'status' => 'created', 'business' => ['id' => $this->created[$id]]];
        }
        return ['items' => $items];
    }
};
$snapshot = $directory.'/snapshot.sqlite';
$pdo = new PDO('sqlite:'.$snapshot, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT); CREATE TABLE places(id TEXT PRIMARY KEY,payload TEXT)');
$pdo->beginTransaction();
$meta = $pdo->prepare('INSERT INTO metadata VALUES (?,?)');
foreach (['schema_version' => '1', 'provider' => 'osm_places', 'import_mode' => 'all_records', 'status' => 'complete', 'country' => 'IL',
    'country_filter' => 'osm_admin_boundary_IL', 'row_count' => '9002', 'snapshot_id' => str_repeat('f', 64), 'release' => '2026-09-15'] as $key => $value) { $meta->execute([$key, $value]); }
$insert = $pdo->prepare('INSERT INTO places VALUES (?,?)');
$mapper = new PlaceMapper;
for ($number = 1; $number <= 9002; $number++) {
    $id = $number === 9002 ? 'way/1' : 'node/'.$number;
    $raw = $mapper->map(['id' => $id, 'country' => 'IL', 'country_filter' => 'osm_admin_boundary_IL', 'lifecycle_status' => 'active',
        'tags' => ['name' => 'Same chain', 'shop' => 'future_category', 'addr:city' => 'Unmapped city', 'phone' => '+97235550100', 'website' => 'https://chain.example.org']], '2026-09-15', '2026-09-16T00:00:00Z');
    $insert->execute([$id, Json::encode($raw)]);
}
$pdo->commit();
$insert = $meta = $pdo = null;
$database = new Database(':memory:');
$normalizer = new BusinessNormalizer(new OpeningHoursParser, []);
$repository = new WorkerRepository($database, $normalizer, new BusinessMerger, ['osm_places']);
$logger = new Logger($directory.'/worker.log');
$source = static fn () => new PlacesSource(['database_path' => $snapshot], dirname(__DIR__), $repository);
$run = static function (int $limit, bool $dry = false) use ($source, $normalizer, $repository, $logger, $api): array {
    $report = new RunReport(Uuid::v4(), 'run', $dry);
    $repository->startRun($report->runId, 'run', $dry, 'fixture');
    $targets = [ResearchTarget::sourceAll('osm_places')];
    $research = new ResearchService([$source()], [], $targets, $normalizer, $repository, $logger, $limit, 1);
    try {
        (new ImportService($api, $repository, new BusinessMerger, $logger, 100))
            ->runTargets($report->runId, $targets, $limit, 1, $limit, $dry, $report, $research);
    } finally { $research->reportProgress($report); }
    return $report->toArray();
};
try {
    $run(1);
    $assert(count($api->created) === 0 && $source()->progress()['pending'] === 1, 'Failed first transport must preserve a durable pending candidate.');
    $report = $run(9000);
    $assert(count($api->created) === 9000 && $report['imported'] === 9000, 'Retry plus fresh OSM records must total at most 9000 per run.');
    $assert($report['osm_progress']['remaining'] === 2, 'Run must stop before the next two source records.');
    $report = $run(9000);
    $assert(count($api->created) === 9002 && $report['imported'] === 2, 'Next run must resume the final two records.');
    $assert(isset($api->created['node/1'], $api->created['way/1']), 'Same names, contacts and numeric IDs across entity types must not merge locally.');
    $assert($report['osm_progress']['scanned'] === 9002 && $report['osm_progress']['remaining'] === 0, 'Final progress must equal the complete snapshot.');
    $batches = $api->batches;
    $report = $run(9000);
    $assert($report['imported'] === 0 && $api->batches === $batches, 'An exhausted snapshot must not repeat writes.');
    echo 'OSM pipeline: '.$assertions." assertions passed (9002 records, mocked API only).\n";
} finally {
    $run = $source = $repository = $database = $logger = null;
    gc_collect_cycles();
    foreach (glob($directory.'/*') as $path) { if (is_file($path)) { unlink($path); } }
    rmdir($directory);
}
