<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\OAuthTokenProvider;
use Sveevee\Worker\Api\SveeveeApiClient;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\SourceFingerprint;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class AllPlacesGateway implements SveeveeGateway
{
    public array $batches = [];

    public array $checks = [];

    public array $created = [];

    public bool $failBatch = false;

    public bool $failCheck = false;

    public function checkDuplicate(array $business): array
    {
        $this->checks[] = $business;
        if ($this->failCheck) {
            throw new ApiException('Fixture unavailable preflight.', 503, 'unavailable', retryable: true);
        }

        return ['matches' => []];
    }

    public function searchBusinesses(array $filters): array
    {
        throw new RuntimeException('Unexpected search: a cached successful source must be skipped.');
    }

    public function importBatch(array $request): array
    {
        $this->batches[] = $request;
        if ($this->failBatch) {
            $this->failBatch = false;
            throw new ApiException('Fixture unavailable batch.', 503, 'unavailable', retryable: true);
        }
        $items = [];
        foreach ($request['businesses'] as $index => $business) {
            $id = $business['source']['id'] ?? null;
            if (! is_string($id) || ($business['source']['provider'] ?? null) !== 'overture_places') {
                throw new RuntimeException('New all-places import is missing stable source provenance.');
            }
            if (isset($this->created[$id])) {
                throw new RuntimeException('A source ID was submitted for creation twice: '.$id);
            }
            if (($business['address']['city'] ?? null) === 'Israel' || ($business['category_key'] ?? null) === 'all_places') {
                throw new RuntimeException('Internal scan labels leaked into business content.');
            }
            $this->created[$id] = 10000 + count($this->created);
            $items[] = ['position' => $index + 1, 'status' => 'created', 'business' => ['id' => $this->created[$id]]];
        }

        return ['items' => $items];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

final class AllPlacesFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public readonly BusinessNormalizer $normalizer;

    public readonly Logger $logger;

    public readonly AllPlacesGateway $gateway;

    public readonly string $snapshot;

    public function __construct(public readonly string $directory, int $count, ?Closure $change = null)
    {
        mkdir($directory, 0700, true);
        $this->snapshot = $directory.'/places.sqlite';
        $this->database = new Database(':memory:');
        $this->normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa']);
        $this->repository = new WorkerRepository($this->database, $this->normalizer, new BusinessMerger, ['overture_places']);
        $this->logger = new Logger($directory.'/worker.log');
        $this->gateway = new AllPlacesGateway;
        $pdo = new PDO('sqlite:'.$this->snapshot, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE places (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, category_key TEXT, city TEXT, street TEXT,
            phone TEXT, email TEXT, website TEXT, social_links TEXT NOT NULL, confidence REAL,
            release TEXT NOT NULL, source_url TEXT NOT NULL, source_name TEXT NOT NULL,
            source_checked_at TEXT NOT NULL, source_metadata TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX places_city_category_id ON places (city, category_key, id)');
        $pdo->beginTransaction();
        $meta = $pdo->prepare('INSERT INTO metadata VALUES (?, ?)');
        foreach (['schema_version' => '2', 'status' => 'complete', 'country' => 'IL', 'import_mode' => 'all_places', 'release' => '2026-08-19.0', 'row_count' => (string) $count, 'prepared_at' => Clock::now()] as $key => $value) {
            $meta->execute([$key, $value]);
        }
        $insert = $pdo->prepare('INSERT INTO places VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($number = 1; $number <= $count; $number++) {
            $row = self::row($number);
            $insert->execute(array_values($change === null ? $row : $change($row, $number)));
        }
        $pdo->commit();
    }

    public static function row(int $number): array
    {
        $id = 'gers-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        $group = intdiv($number, 4);

        return [
            'id' => $id, 'name' => 'Chain '.$group,
            'category_key' => $number === 1 ? 'food_catering.cafes' : ($number % 3 === 0 ? 'health_care.dentists' : null),
            'city' => $number === 1 ? 'Haifa' : ($number % 3 === 0 ? 'Unlisted village '.$group : null),
            'street' => $number === 1 ? 'Legacy road 1' : ($number % 2 === 0 ? 'Branch road '.$number : null),
            'phone' => '+9723'.str_pad((string) $group, 7, '0', STR_PAD_LEFT), 'email' => null,
            'website' => 'https://chain'.$group.'.example.org', 'social_links' => '{}',
            'confidence' => $number === 1 ? 0.9 : 0.01, 'release' => '2026-08-19.0',
            'source_url' => 'https://explore.overturemaps.org/?feature=places.place.'.$id,
            'source_name' => 'Overture Maps Places', 'source_checked_at' => Clock::now(),
            'source_metadata' => Json::encode(['gers_id' => $id, 'address' => ['country' => 'IL'], 'taxonomy' => null]),
        ];
    }

    public function source(): OverturePlacesSource
    {
        return new OverturePlacesSource(['database_path' => $this->snapshot, 'import_mode' => 'all_places', 'min_confidence' => 0.75], dirname(__DIR__), $this->repository);
    }

    public function run(int $limit, int $batchSize = 100, bool $dryRun = false, ?ApiException &$error = null): array
    {
        $error = null;
        $report = new RunReport(Uuid::v4(), 'run', $dryRun);
        $this->repository->startRun($report->runId, 'run', $dryRun, 'all-places-fixture');
        $targets = [ResearchTarget::overtureAll()];
        $research = new ResearchService([$this->source()], [], $targets, $this->normalizer, $this->repository, $this->logger, $limit, 1);
        try {
            (new ImportService($this->gateway, $this->repository, new BusinessMerger, $this->logger, $batchSize))
                ->runTargets($report->runId, $targets, $limit, 1, $limit, $dryRun, $report, $research);
        } catch (ApiException $exception) {
            $error = $exception;
        } finally {
            $research->reportProgress($report);
        }
        $result = $report->toArray($error === null ? 'completed' : 'failed');
        $this->repository->finishRun($report->runId, $result['status'], null, $result);

        return $result;
    }

    public function scalar(string $sql): mixed
    {
        return $this->database->pdo->query($sql)->fetchColumn();
    }
}

$root = sys_get_temp_dir().'/sveevee-all-places-'.bin2hex(random_bytes(8));
$directories = [];
$fixture = static function (int $count, ?Closure $change = null) use ($root, &$directories): AllPlacesFixture {
    $directory = $root.'/case-'.count($directories);
    $directories[] = $directory;

    return new AllPlacesFixture($directory, $count, $change);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$tests = [];
$tests['9017 source rows retain one legacy success and import 9000, then 16, then zero'] = static function () use ($fixture, $assert): void {
    $f = $fixture(9017);
    $initialSource = $f->source();
    $iterator = $initialSource->research(ResearchTarget::overtureAll(), 1);
    $raw = $iterator->current();
    unset($iterator, $initialSource);
    $legacy = $f->normalizer->normalize($raw, new ResearchTarget('Haifa', 'food_catering.cafes'), 'overture_places');
    $assert(! isset($legacy->data['source']), 'Preloaded legacy candidate must not contain the new API source payload.');
    $stored = $f->repository->upsertCandidate($legacy);
    $f->repository->markBusiness($stored['business_id'], 'imported', 77);
    $originalSource = $f->database->pdo->query('SELECT * FROM business_sources')->fetchAll();
    $first = $f->run(9000);
    $assert($first['imported'] === 9000 && $first['found'] === 9000 && $first['failed'] === 0, 'First full run did not complete 9000 actual writes.');
    $assert(count($f->gateway->batches) === 90 && count($f->gateway->checks) === 9000, 'Expected 90 hundred-item batches and no preflight for the legacy success.');
    $assert($first['overture_progress']['scanned'] === 9001 && $first['overture_progress']['remaining'] === 16, 'Cursor must include the already successful source ID.');
    foreach ($f->gateway->batches as $batch) {
        $assert(count($batch['businesses']) === 100, 'A request exceeded or undershot the hundred-item batch size.');
    }
    $second = $f->run(9000);
    $assert($second['imported'] === 16 && $second['found'] === 16 && $second['failed'] === 0, 'Second run did not resume the remaining sixteen rows.');
    $assert($second['overture_progress']['scanned'] === 9017 && $second['overture_progress']['remaining'] === 0, 'Final cursor did not reach the full snapshot.');
    $third = $f->run(9000);
    $assert($third['found'] === 0 && $third['imported'] === 0 && count($f->gateway->batches) === 91, 'A completed snapshot was scanned/imported again.');
    $assert((int) $f->scalar('SELECT COUNT(*) FROM businesses') === 9017 && (int) $f->scalar('SELECT COUNT(DISTINCT source_url) FROM business_sources') === 9017, 'A low-confidence row or shared-contact branch was dropped.');
    $assert($f->repository->business($stored['business_id'])['sveevee_page_id'] === 77, 'Existing page association changed.');
    $assert($f->database->pdo->query('SELECT * FROM business_sources WHERE business_id = '.$stored['business_id'])->fetchAll() === $originalSource, 'Unchanged legacy source history was rewritten.');
};
$tests['a failed batch keeps its UUID and request and consumes the next run quota on retry'] = static function () use ($fixture, $assert): void {
    $f = $fixture(7);
    $f->gateway->failBatch = true;
    $first = $f->run(5, 5);
    $pending = $f->repository->pendingBatches();
    $assert($first['imported'] === 0 && count($pending) === 1 && (int) $pending[0]['attempts'] === 1, 'Failed batch was lost or treated as successful.');
    $request = $pending[0]['request'];
    $assert(count($request['businesses']) === 5 && $first['overture_progress']['scanned'] === 5, 'Batch boundary did not preserve five queued candidates.');
    $second = $f->run(5, 5);
    $assert($second['imported'] === 5 && $second['found'] === 0 && $second['overture_progress']['scanned'] === 5, 'Successful retry failed to consume the complete next run quota.');
    $assert($f->gateway->batches[0] === $request && $f->gateway->batches[1] === $request && $f->repository->pendingBatches() === [], 'Retry altered the body or idempotency ID.');
    $third = $f->run(5, 5);
    $assert($third['imported'] === 2 && $third['overture_progress']['scanned'] === 7 && count($f->gateway->created) === 7, 'Remaining sources were lost after the retry.');
};
$tests['dry run persists four pending candidates and real runs finish four then two'] = static function () use ($fixture, $assert): void {
    $f = $fixture(6);
    $dry = $f->run(4, 100, true);
    $assert($dry['planned_imports'] === 4 && $dry['imported'] === 0 && $f->gateway->batches === [], 'Dry run sent writes or planned the wrong number of records.');
    $assert($dry['overture_progress']['scanned'] === 4 && $dry['overture_progress']['pending'] === 4, 'Dry-run cursor advanced without durable candidates.');
    $first = $f->run(4);
    $assert($first['imported'] === 4 && $first['found'] === 0 && $first['overture_progress']['scanned'] === 4, 'Real import did not consume the pending dry-run rows first.');
    $second = $f->run(4);
    $assert($second['imported'] === 2 && $second['found'] === 2 && $second['overture_progress']['scanned'] === 6, 'Dry-run continuation lost remaining source rows.');
};
$tests['preflight outage stops after one check and recovery imports the pending row and remaining sources'] = static function () use ($fixture, $assert): void {
    $f = $fixture(5);
    $f->gateway->failCheck = true;
    $first = $f->run(5, 100, false, $error);
    $assert($error instanceof ApiException && $error->status === 503, 'Retryable preflight failure must stop this run.');
    $assert(count($f->gateway->checks) === 1 && $f->gateway->batches === [], 'Outage checked every remaining source or sent writes.');
    $assert($first['overture_progress']['scanned'] === 1 && (int) $f->scalar("SELECT COUNT(*) FROM businesses WHERE status='pending'") === 1, 'Failed preflight lost the already scanned candidate.');
    $f->gateway->failCheck = false;
    $recovered = $f->run(5);
    $assert($recovered['imported'] === 5 && $recovered['failed'] === 0 && $recovered['overture_progress']['scanned'] === 5, 'Recovery omitted the pending source or failed to continue.');
};
$tests['a new snapshot resets the cursor, finds a lower GERS ID and skips unchanged earlier imports'] = static function () use ($fixture, $assert): void {
    $f = $fixture(3);
    $first = $f->run(5);
    $assert($first['imported'] === 3, 'Initial snapshot was not imported.');
    $pdo = new PDO('sqlite:'.$f->snapshot, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = AllPlacesFixture::row(0);
    $row['name'] = 'New earlier source';
    $row['release'] = '2026-09-16.0';
    $pdo->prepare('INSERT INTO places VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($row));
    $pdo->exec("UPDATE metadata SET value='2026-09-16.0' WHERE key='release'");
    $pdo->exec("UPDATE metadata SET value='4' WHERE key='row_count'");
    $pdo->exec("UPDATE places SET release='2026-09-16.0'");
    $pdo = null;
    $next = $f->run(5);
    $assert($next['found'] === 1 && $next['imported'] === 1 && $next['failed'] === 0, 'Release metadata reimported unchanged places or missed a lower ID.');
    $assert(isset($f->gateway->created['gers-000000']) && $next['overture_progress']['scanned'] === 4 && $next['overture_progress']['remaining'] === 0, 'New snapshot did not get a complete independent cursor.');
    $assert((int) $f->scalar('SELECT COUNT(*) FROM source_scan_progress') === 2, 'Previous snapshot progress was destructively overwritten.');
};
$tests['all-places refuses a legacy filtered snapshot'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1);
    $pdo = new PDO('sqlite:'.$f->snapshot);
    $pdo->exec("UPDATE metadata SET value='1' WHERE key='schema_version'");
    $pdo = null;
    try {
        iterator_to_array($f->source()->research(ResearchTarget::overtureAll(), 10));
        throw new LogicException('Legacy schema unexpectedly accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'full schema 2 snapshot'), 'Wrong rejection for an old filtered snapshot.');
    }
    $assert((int) $f->scalar('SELECT COUNT(*) FROM source_scan_progress') === 0, 'Rejected schema altered scan progress.');
};
$tests['shared contacts and missing locations remain separate sources with no invented scope fields'] = static function () use ($fixture, $assert): void {
    $f = $fixture(4, static fn (array $row): array => array_replace($row, [
        'name' => 'Shared chain', 'city' => null, 'street' => null, 'category_key' => null,
        'confidence' => null, 'phone' => '+97235550100', 'website' => 'https://same.example.org',
    ]));
    $report = $f->run(10);
    $assert($report['imported'] === 4 && $report['failed'] === 0 && (int) $f->scalar('SELECT COUNT(*) FROM businesses') === 4, 'Different source IDs were collapsed by incomplete locations or shared contacts.');
    foreach ($f->gateway->batches[0]['businesses'] as $business) {
        $assert(($business['address'] ?? []) === [] && ! isset($business['category_key']) && ! isset($business['service_areas']), 'Missing content was replaced by internal scan labels or fake areas.');
        $assert(($business['source']['metadata']['address']['country'] ?? null) === 'IL', 'Country provenance must survive nullable public fields.');
        $assert(array_key_exists('confidence', $business['source']['metadata']) && $business['source']['metadata']['confidence'] === null, 'Unknown confidence must not become a fabricated zero.');
    }
    $again = $f->run(10);
    $assert($again['found'] === 0 && $again['imported'] === 0, 'Stable source IDs were not respected on the following run.');
};
$tests['known street and numbered branches do not absorb incomplete same-name contact matches'] = static function () use ($fixture, $assert): void {
    $f = $fixture(4, static fn (array $row, int $number): array => array_replace($row, [
        'name' => 'Shared chain', 'city' => $number === 1 ? null : 'Haifa',
        'street' => [null, 'Main road', 'Main road 10', 'Main road 20'][$number - 1],
        'phone' => '+97235550100', 'website' => 'https://same.example.org',
    ]));
    $report = $f->run(10);
    $assert($report['imported'] === 4 && $report['failed'] === 0 && count($f->gateway->created) === 4, 'Incomplete or unnumbered contact match absorbed a different numbered branch.');
    $assert((int) $f->scalar('SELECT COUNT(*) FROM businesses') === 4 && (int) $f->scalar('SELECT COUNT(*) FROM business_sources') === 4, 'Stable source provenance collapsed across branches.');
};
$tests['real API client keeps source provenance in duplicate-check HTTP payloads'] = static function () use ($assert): void {
    $http = new class implements HttpClientInterface
    {
        public array $requests = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
        {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
            if (str_ends_with($url, '/token')) {
                return new HttpResponse(200, [], Json::encode(['access_token' => 'fixture-token', 'expires_in' => 3600]));
            }

            return new HttpResponse(200, [], Json::encode(['success' => true, 'data' => ['matches' => []]]));
        }
    };
    $tokens = new OAuthTokenProvider($http, 'https://fixture.example.org/token', 'fixture-client', 'fixture-secret', 5, 'AllPlacesTest');
    $api = new SveeveeApiClient($http, $tokens, 'https://fixture.example.org/import', 5, 0, 0, 'AllPlacesTest');
    $source = ['provider' => 'overture_places', 'id' => 'gers-http', 'url' => 'https://explore.overturemaps.org/?feature=places.place.gers-http', 'metadata' => ['address' => ['country' => 'IL']]];
    $result = $api->checkDuplicate(['type' => 'business', 'name' => 'Without address', 'address' => [], 'source' => $source, 'public_description' => 'Not part of duplicate lookup']);
    $assert($result === ['matches' => []] && count($http->requests) === 2, 'Actual client duplicate request failed.');
    $request = $http->requests[1];
    $payload = Json::decode($request['body']);
    $assert($request['method'] === 'POST' && str_ends_with($request['url'], '/businesses/duplicates'), 'Wrong duplicate-check endpoint or method.');
    $assert($payload['source'] === $source && $payload['address'] === [] && ! isset($payload['public_description']), 'HTTP whitelist dropped stable source ID/provenance or invented missing fields.');
};
$tests['full mode retries an unchanged legacy invalid business with source provenance'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1);
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::overtureAll(), 1);
    $raw = $iterator->current();
    unset($iterator, $source);
    $legacy = $f->normalizer->normalize($raw, new ResearchTarget('Haifa', 'food_catering.cafes'), 'overture_places');
    $assert(! isset($legacy->data['source']), 'Legacy invalid fixture already has new source provenance.');
    $stored = $f->repository->upsertCandidate($legacy);
    $f->repository->markBusiness($stored['business_id'], 'invalid', errorCode: 'legacy_validation', errorMessage: 'Missing legacy-required field.');
    $hash = SourceFingerprint::hash($raw);
    $assert(! $f->repository->shouldProcessUrl('overture_places', $raw['source_url'], 30, $hash), 'Ordinary source calls unexpectedly retry unchanged legacy failures.');
    $assert($f->repository->shouldProcessUrl('overture_places', $raw['source_url'], 30, $hash, true), 'Full mode did not reconsider an unchanged legacy failure.');
    $report = $f->run(1);
    $business = $f->repository->business($stored['business_id']);
    $assert($report['found'] === 1 && $report['imported'] === 1 && $report['failed'] === 0, 'Legacy invalid row was skipped instead of requeued and imported.');
    $assert($business['status'] === 'imported' && ($business['payload']['source']['provider'] ?? null) === 'overture_places', 'Recovered business lacks source provenance or successful status.');
    $assert((int) $f->scalar('SELECT COUNT(*) FROM businesses') === 1 && (int) $f->scalar('SELECT COUNT(*) FROM business_sources') === 1, 'Legacy recovery replaced the business or duplicated source history.');
    $assert($report['overture_progress']['scanned'] === 1 && $report['overture_progress']['failed'] === 0 && $report['overture_progress']['pending'] === 0, 'Recovered legacy failure remains in the queue counts.');
};
$tests['rejected sources without businesses remain visible and full mode can reconsider them'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1);
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::overtureAll(), 1);
    $raw = $iterator->current();
    unset($iterator, $source);
    $runId = Uuid::v4();
    $f->repository->startRun($runId, 'run', false, 'legacy-rejection-fixture');
    $f->repository->recordResearchFailure($runId, 'overture_places', $raw['source_url'], $raw, 'identity_conflict', 'Legacy contact-only collision.', false);
    $progress = $f->source()->progress();
    $assert($progress['failed'] === 1 && $progress['pending'] === 0 && (int) $f->scalar('SELECT COUNT(*) FROM businesses') === 0, 'Permanent source failure disappeared from progress or became a pending business.');
    $hash = SourceFingerprint::hash($raw);
    $assert(! $f->repository->shouldProcessUrl('overture_places', $raw['source_url'], 30, $hash), 'Ordinary research now retries an unchanged permanent rejection.');
    $assert($f->repository->shouldProcessUrl('overture_places', $raw['source_url'], 30, $hash, true), 'Full mode did not reconsider a previous source rejection.');
    $report = $f->run(1);
    $assert($report['found'] === 1 && $report['imported'] === 1 && $report['overture_progress']['failed'] === 0, 'Previously rejected source was not recovered or remains falsely failed.');
    $assert((int) $f->scalar('SELECT COUNT(*) FROM research_failures') === 1, 'Successful reconsideration deleted the historical rejection audit.');
};
$tests['failed local candidate insertion leaves the source cursor unchanged and recovers every row'] = static function () use ($fixture, $assert): void {
    $f = $fixture(5);
    $f->database->pdo->exec("CREATE TRIGGER fixture_insert_failure BEFORE INSERT ON businesses BEGIN SELECT RAISE(FAIL, 'Fixture local insert unavailable'); END");
    try {
        $f->run(5);
        throw new LogicException('Local storage failure did not stop full-snapshot research.');
    } catch (PDOException $error) {
        $assert(str_contains($error->getMessage(), 'Fixture local insert unavailable'), 'Unexpected storage exception.');
    }
    $progress = $f->source()->progress();
    $assert($progress['scanned'] === 0 && $progress['remaining'] === 5 && (int) $f->scalar('SELECT COUNT(*) FROM businesses') === 0, 'Cursor advanced past a candidate that was never persisted.');
    $assert((int) $f->scalar("SELECT COUNT(*) FROM research_failures WHERE error_code='research_error'") === 1 && (int) $f->scalar("SELECT COUNT(*) FROM researched_urls WHERE status='failed'") === 1, 'Retryable failure audit was not retained.');
    $assert($f->gateway->checks === [] && $f->gateway->batches === [], 'Storage failure reached the remote API.');
    $f->database->pdo->exec('DROP TRIGGER fixture_insert_failure');
    $recovered = $f->run(5);
    $assert($recovered['imported'] === 5 && $recovered['found'] === 5 && $recovered['failed'] === 0 && $recovered['overture_progress']['scanned'] === 5, 'A failed source row was permanently lost after storage recovered.');
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
    foreach ($directories as $directory) {
        foreach (['places.sqlite', 'places.sqlite-journal', 'worker.log'] as $name) {
            $path = $directory.'/'.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
    restore_error_handler();
}
fwrite(STDOUT, count($tests).' tests, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
