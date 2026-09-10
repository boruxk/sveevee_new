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
use Sveevee\Worker\Research\Foursquare\PlaceMapper;
use Sveevee\Worker\Research\Foursquare\PlacesSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class FoursquareGateway implements SveeveeGateway
{
    public array $batches = [];

    public array $checks = [];

    public array $searches = [];

    public array $created = [];

    public array $reviews = [];

    public bool $failBatch = false;

    public ?Closure $check = null;

    public ?array $remote = null;

    public function checkDuplicate(array $business): array
    {
        if (isset($business['source']['metadata']['preparation_error'])) {
            throw new RuntimeException('An invalid source name was sent to preflight.');
        }
        $this->checks[] = $business;

        return $this->check === null ? ['matches' => []] : ($this->check)($business);
    }

    public function searchBusinesses(array $filters): array
    {
        $this->searches[] = $filters;

        return ['businesses' => $this->remote === null ? [] : [$this->remote]];
    }

    public function importBatch(array $request): array
    {
        $this->batches[] = $request;
        if ($this->failBatch) {
            $this->failBatch = false;
            throw new ApiException('Fixture unavailable.', 503, 'unavailable', retryable: true);
        }
        $items = [];
        foreach ($request['businesses'] as $index => $business) {
            $id = $business['source']['id'];
            if (($business['source']['provider'] ?? null) !== 'foursquare_places' || isset($this->created[$id])) {
                throw new RuntimeException('Missing provenance or duplicate create.');
            }
            if (! empty($business['source']['metadata']['date_closed'])) {
                throw new RuntimeException('A closed source was sent to the API.');
            }
            if (isset($business['source']['metadata']['preparation_error'])) {
                throw new RuntimeException('An invalid source name was sent to the API.');
            }
            if (isset($this->reviews[$id])) {
                $items[] = ['position' => $index + 1, 'status' => 'review_required', 'review_id' => 17];

                continue;
            }
            $this->created[$id] = $business['id'] ?? 10000 + count($this->created);
            $items[] = ['position' => $index + 1, 'status' => isset($business['id']) ? 'updated' : 'created',
                'business' => ['id' => $this->created[$id]]];
        }

        return ['items' => $items];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

final class FoursquareFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public readonly BusinessNormalizer $normalizer;

    public readonly FoursquareGateway $api;

    public readonly string $snapshot;

    public readonly Logger $logger;

    public function __construct(public readonly string $directory, int $count, ?Closure $change = null)
    {
        mkdir($directory, 0700, true);
        $this->snapshot = $directory.'/places.sqlite';
        $this->database = new Database(':memory:');
        $this->normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa']);
        $this->repository = new WorkerRepository($this->database, $this->normalizer, new BusinessMerger, ['foursquare_places']);
        $this->api = new FoursquareGateway;
        $this->logger = new Logger($directory.'/worker.log');
        $pdo = new PDO('sqlite:'.$this->snapshot, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE places (id TEXT PRIMARY KEY, name TEXT NOT NULL, category_key TEXT, city TEXT, street TEXT,
            phone TEXT, email TEXT, website TEXT, social_links TEXT NOT NULL, confidence REAL, release TEXT NOT NULL,
            source_url TEXT NOT NULL, source_name TEXT NOT NULL, source_checked_at TEXT NOT NULL, source_metadata TEXT NOT NULL)');
        $pdo->beginTransaction();
        $meta = $pdo->prepare('INSERT INTO metadata VALUES (?,?)');
        foreach (['schema_version' => '1', 'provider' => 'foursquare_places', 'import_mode' => 'all_records',
            'status' => 'complete', 'country' => 'IL', 'row_count' => (string) $count, 'release' => 'snapshot-123', 'snapshot_id' => '123'] as $key => $value) {
            $meta->execute([$key, $value]);
        }
        $insert = $pdo->prepare('INSERT INTO places VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $mapper = new PlaceMapper(['Haifa'], ['Haifa' => ['חיפה']]);
        for ($number = 1; $number <= $count; $number++) {
            $raw = self::raw($number);
            $row = $mapper->map($change === null ? $raw : $change($raw, $number), 'snapshot-123', '2026-09-10T00:00:00Z');
            foreach (['social_links', 'source_metadata'] as $field) {
                $row[$field] = Json::encode((object) $row[$field]);
            }
            $insert->execute(array_values($row));
        }
        $pdo->commit();
    }

    public static function id(int $number): string
    {
        return str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    public static function raw(int $number): array
    {
        return ['fsq_place_id' => self::id($number), 'name' => 'Chain '.intdiv($number, 4),
            'country' => 'IL', 'address' => $number % 2 === 0 ? 'Branch road '.$number : null,
            'locality' => $number % 3 === 0 ? 'Unlisted village' : null,
            'fsq_category_ids' => ['future-category'], 'fsq_category_labels' => ['Unknown category'],
            'date_closed' => null, 'tel' => '03-5550100', 'website' => 'https://same.example.org',
            'latitude' => 32.1, 'longitude' => 34.8];
    }

    public function source(): PlacesSource
    {
        return new PlacesSource(['database_path' => $this->snapshot, 'import_mode' => 'all_records'], dirname(__DIR__), $this->repository);
    }

    public function run(int $limit, int $batch = 100, bool $dry = false): array
    {
        $report = new RunReport(Uuid::v4(), 'run', $dry);
        $this->repository->startRun($report->runId, 'run', $dry, 'fixture');
        $targets = [ResearchTarget::sourceAll('foursquare_places')];
        $research = new ResearchService([$this->source()], [], $targets, $this->normalizer, $this->repository, $this->logger, $limit, 1);
        try {
            (new ImportService($this->api, $this->repository, new BusinessMerger, $this->logger, $batch))
                ->runTargets($report->runId, $targets, $limit, 1, $limit, $dry, $report, $research);
        } finally {
            $research->reportProgress($report);
        }

        return $report->toArray();
    }
}

$root = sys_get_temp_dir().'/sveevee-foursquare-'.bin2hex(random_bytes(8));
$directories = [];
$fixture = static function (int $count, ?Closure $change = null) use ($root, &$directories): FoursquareFixture {
    $path = $root.'/case-'.count($directories);
    $directories[] = $path;

    return new FoursquareFixture($path, $count, $change);
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$tests = [];
$tests['mapper preserves original locality, category hierarchy, dates, flags and numeric Facebook IDs'] = static function () use ($assert): void {
    $mapper = new PlaceMapper(['Haifa'], ['Haifa' => ['חיפה']]);
    $raw = array_replace(FoursquareFixture::raw(1), ['locality' => 'חיפה', 'address' => 'Herzl 1',
        'fsq_category_ids' => ['id-one', 'id-two'], 'fsq_category_labels' => ['Dining and Drinking > Restaurant > Thai Restaurant', 'Unknown category'],
        'facebook_id' => 12345678901234, 'instagram' => 'fixture_handle', 'email' => 'TEST@EXAMPLE.ORG',
        'date_closed' => '2024-01-01', 'unresolved_flags' => ['privatevenue']]);
    $row = $mapper->map($raw, 'fixture', '2026-09-10T00:00:00Z');
    $assert($row['city'] === 'Haifa' && $row['source_metadata']['source_city'] === 'חיפה', 'Canonical and source locality must both survive.');
    $assert($row['category_key'] === 'food_catering.restaurants' && count($row['source_metadata']['source_categories']) === 2, 'Known ancestor or unknown category was lost.');
    $assert($row['source_metadata']['original_record'] === $raw && $row['source_metadata']['date_closed'] === '2024-01-01', 'Original fields or closure date were lost.');
    $assert($row['social_links']->facebook === 'https://facebook.com/12345678901234' && $row['email'] === 'test@example.org', 'Contacts were not normalized.');
    $sparse = $mapper->map(FoursquareFixture::raw(1), 'fixture', '2026-09-10T00:00:00Z');
    $assert($sparse['city'] === null && $sparse['street'] === null && $sparse['category_key'] === null, 'Missing attributes became invented facts.');
    try {
        $mapper->map(array_replace($raw, ['fsq_place_id' => 'invalid']), 'fixture', 'now');
        throw new LogicException('Invalid identity accepted.');
    } catch (RuntimeException $error) {
        $assert(! $error instanceof LogicException, 'Invalid identity was accepted.');
    }
    $assert($mapper->map(array_replace($raw, ['country' => 'US']), 'fixture', 'now', $reason) === null && $reason === 'country', 'Foreign country was not explicitly rejected.');
};
$tests['unusable names retain original text and are explicitly marked without a stored surrogate'] = static function () use ($assert): void {
    $mapper = new PlaceMapper;
    foreach (['???', "\u{1F600}", '', null, 'בע״מ'] as $name) {
        $row = $mapper->map(array_replace(FoursquareFixture::raw(1), ['name' => $name]), 'fixture', 'now');
        $assert($row['name'] === ($name ?? '') && $row['source_metadata']['original_record']['name'] === $name, 'Original unusable name was replaced or lost.');
        $assert(($row['source_metadata']['preparation_error'] ?? null) === 'invalid_business_name', 'Unusable source name was not marked.');
        $assert(! str_contains(Json::encode($row), 'Foursquare '.FoursquareFixture::id(1)), 'Temporary normalizer name leaked into the snapshot.');
    }
};
$tests['invalid and closed records are separately acknowledged across quotas and never reach the API'] = static function () use ($fixture, $assert): void {
    $f = $fixture(6, static fn (array $row, int $n): array => array_replace($row, [
        'name' => match ($n) {
            1 => '???', 2 => "\u{1F600}", 4 => '', default => $row['name']
        },
        'date_closed' => in_array($n, [2, 5], true) ? '2025-01-01' : null,
    ]));
    $initial = $f->source()->progress();
    $assert($initial['scanned'] === 0 && $initial['invalid'] === 0 && $initial['closed'] === 0, 'Progress counted records before they were scanned.');
    $first = $f->run(1);
    $progress = $first['foursquare_progress'];
    $assert($first['imported'] === 1 && $progress['scanned'] === 3 && $progress['invalid'] === 1 && $progress['closed'] === 1, 'First quota lost skips or double-counted a closed invalid name.');
    $assert($progress['total'] === 6 && $progress['remaining'] === 3 && $first['failed'] === 0, 'Unusable names reduced the snapshot total or became import failures.');
    $second = $f->run(2);
    $progress = $second['foursquare_progress'];
    $assert($second['imported'] === 1 && $progress['scanned'] === 6 && $progress['remaining'] === 0 && $progress['invalid'] === 2 && $progress['closed'] === 2, 'Resume did not retain independent invalid and closed counts.');
    $assert(array_keys($f->api->created) === [FoursquareFixture::id(3), FoursquareFixture::id(6)] && count($f->api->checks) === 2, 'Unusable names or closed records reached the API.');
    $again = $f->run(2);
    $assert($again['found'] === 0 && $again['imported'] === 0 && $again['foursquare_progress'] === $progress, 'Completed skipped records restarted or lost progress.');
};
$tests['blank names without an explicit preparation error remain a corrupt snapshot'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1);
    $pdo = new PDO('sqlite:'.$f->snapshot);
    $pdo->exec("UPDATE places SET name=''");
    $pdo = null;
    $source = $f->source();
    try {
        $source->research(ResearchTarget::sourceAll('foursquare_places'), 1)->current();
        throw new LogicException('Unmarked blank name was accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'invalid identity'), 'Unexpected corrupt-record error.');
    }
    $assert($source->progress()['scanned'] === 0 && $f->api->checks === [], 'Corrupt snapshot advanced or reached the API.');
};
$tests['9012 places import 9000 then eight with four closed records counted and no category limit'] = static function () use ($fixture, $assert): void {
    $f = $fixture(9012, static fn (array $row, int $n): array => array_replace($row, ['date_closed' => in_array($n, [1, 3000, 9005, 9012], true) ? '2025-01-01' : null]));
    $first = $f->run(9000);
    $assert($first['imported'] === 9000 && $first['failed'] === 0 && count($f->api->batches) === 90, 'The first run did not import 9000 in ninety batches.');
    $assert(array_unique(array_map(static fn ($batch) => count($batch['businesses']), $f->api->batches)) === [100], 'Batch size must remain 100.');
    $assert($first['foursquare_progress']['scanned'] === 9002 && $first['foursquare_progress']['closed'] === 2, 'Closed rows were not included in durable progress.');
    $second = $f->run(9000);
    $assert($second['imported'] === 8 && $second['foursquare_progress']['scanned'] === 9012 && $second['foursquare_progress']['closed'] === 4, 'Remaining records were lost.');
    $third = $f->run(9000);
    $assert($third['found'] === 0 && $third['imported'] === 0 && count($f->api->checks) === 9008, 'Completed snapshot restarted or closed rows reached preflight.');
    $payload = $f->api->batches[0]['businesses'][0];
    $assert(! isset($payload['category_key'], $payload['service_areas']) && ($payload['address']['city'] ?? null) !== 'Israel', 'Internal labels leaked into content.');
};
$tests['unacknowledged iterator cannot skip a row and a new reader resumes it'] = static function () use ($fixture, $assert): void {
    $f = $fixture(3);
    $source = $f->source();
    $rows = iterator_to_array($source->research(ResearchTarget::sourceAll('foursquare_places'), 3));
    $assert(count($rows) === 1 && $source->progress()['scanned'] === 0, 'Iterator advanced without acknowledgement.');
    $next = $f->source()->research(ResearchTarget::sourceAll('foursquare_places'), 1)->current();
    $assert($next['source_metadata']['source_id'] === FoursquareFixture::id(1), 'Fresh reader skipped unacknowledged row.');
    $source->acknowledge($rows[0]);
    $source->acknowledge($rows[0]);
    $assert($source->progress()['scanned'] === 1, 'Repeated acknowledgement counted twice.');
};
$tests['retry preserves UUID and payload and consumes next run quota before further research'] = static function () use ($fixture, $assert): void {
    $f = $fixture(7);
    $f->api->failBatch = true;
    $first = $f->run(5, 5);
    $assert($first['imported'] === 0 && $first['foursquare_progress']['pending'] === 5, 'Failed batch lost queued source rows.');
    $second = $f->run(5, 5);
    $assert($second['imported'] === 5 && $second['found'] === 0 && $f->api->batches[0] === $f->api->batches[1], 'Retry changed request or scanned beyond quota.');
    $third = $f->run(5, 5);
    $assert($third['imported'] === 2 && $third['foursquare_progress']['remaining'] === 0, 'Retry continuation lost remaining sources.');
};
$tests['source storage failure does not advance cursor'] = static function () use ($fixture, $assert): void {
    $f = $fixture(2);
    $f->database->pdo->exec("CREATE TRIGGER fixture_failure BEFORE INSERT ON businesses BEGIN SELECT RAISE(FAIL, 'fixture unavailable'); END");
    try {
        $f->run(2);
        throw new LogicException('Storage failure swallowed.');
    } catch (PDOException) {
        $assert($f->source()->progress()['scanned'] === 0, 'Failed candidate advanced cursor.');
    }
    $f->database->pdo->exec('DROP TRIGGER fixture_failure');
    $assert($f->run(2)['imported'] === 2, 'Recovery failed to import both rows.');
};
$tests['preflight and batch reviews are terminal and do not consume successful import slots'] = static function () use ($fixture, $assert): void {
    $f = $fixture(4);
    $f->api->check = static function (array $row): array {
        if ($row['source']['id'] === FoursquareFixture::id(1)) {
            throw new ApiException('Review required.', 409, 'review_required', data: ['review_id' => 1]);
        }

        return ['matches' => []];
    };
    $f->api->reviews[FoursquareFixture::id(2)] = true;
    $report = $f->run(2, 2);
    $assert($report['imported'] === 2 && $report['failed'] === 0 && $report['review'] === 2, 'Reviews became failures or consumed successful slots.');
    $assert($report['foursquare_progress']['review'] === 2 && $f->repository->resetFailed(100) === 0, 'Reviews were queued for failed-record retry.');
    $again = $f->run(2);
    $assert($again['found'] === 0 && $again['imported'] === 0 && count($f->api->checks) === 4, 'Reviewed rows were automatically resubmitted.');
};
$tests['verified source alias resolves by ID, fills missing contacts and preserves claimed pages'] = static function () use ($fixture, $assert): void {
    foreach ([true, false] as $unclaimed) {
        $f = $fixture(1);
        $f->api->check = static fn (): array => ['matches' => [['id' => 77, 'matched_on' => ['source_alias']]]];
        $f->api->remote = ['id' => 77, 'name' => 'Different spelling', 'address' => [], 'can_update' => $unclaimed];
        $report = $f->run(1);
        $assert($f->api->searches === [['id' => 77, 'per_page' => 1]], 'Source alias used a fuzzy name search.');
        $assert($report['failed'] === 0 && ($unclaimed ? $report['updated'] === 1 : $f->api->batches === []), 'Alias missing location or claimed page mishandled.');
        if ($unclaimed) {
            $patch = $f->api->batches[0]['businesses'][0];
            $assert($patch['id'] === 77 && $patch['name'] === 'Chain 0' && $patch['address'] === [], 'Alias patch lost independent source identity evidence.');
        }
    }
};
$tests['invalid snapshot metadata fails before source progress changes'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1);
    $pdo = new PDO('sqlite:'.$f->snapshot);
    $pdo->exec("UPDATE metadata SET value='2' WHERE key='row_count'");
    $pdo = null;
    try {
        $f->source()->research(ResearchTarget::sourceAll('foursquare_places'), 1)->current();
        throw new LogicException('Invalid snapshot accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'inconsistent'), 'Wrong snapshot failure.');
    }
    $assert((int) $f->database->pdo->query('SELECT COUNT(*) FROM source_scan_progress')->fetchColumn() === 0, 'Invalid snapshot changed state.');
};
$tests['dry run tells duplicate checks to avoid review persistence and retains pending candidates'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1);
    $f->api->check = static function (array $business) use ($assert): array {
        $assert(($business['dry_run'] ?? false) === true, 'Dry-run review check omitted no-write flag.');
        throw new ApiException('Review preview.', 409, 'review_required', data: ['review_id' => null]);
    };
    $report = $f->run(1, 100, true);
    $assert($report['failed'] === 0 && $report['review'] === 1 && $f->api->batches === [], 'Dry-run review caused a write or failure.');
    $assert($report['foursquare_progress']['pending'] === 1 && $report['foursquare_progress']['review'] === 0, 'Dry run made a pending candidate terminal.');
};
$tests['review persists across changed source content and separate FSQ IDs never merge before backend lookup'] = static function () use ($fixture, $assert): void {
    $f = $fixture(2, static fn (array $row): array => array_replace($row, ['name' => 'Same place', 'locality' => 'Haifa', 'address' => 'Main road 10']));
    $f->api->check = static function (): array {
        throw new ApiException('Retain review reason.', 409, 'review_required');
    };
    $f->run(2);
    $assert(count($f->api->checks) === 2 && $f->repository->foursquareQueueCounts()['review'] === 2, 'Distinct FSQ IDs disappeared into a local heuristic merge.');
    $raw = $f->database->pdo->query('SELECT raw_json FROM business_sources ORDER BY id LIMIT 1')->fetchColumn();
    $raw = Json::decode($raw);
    $raw['source_metadata']['release'] = 'new-release';
    $candidate = $f->normalizer->normalize($raw, ResearchTarget::sourceAll('foursquare_places'), 'foursquare_places');
    $stored = $f->repository->upsertCandidate($candidate);
    $business = $f->repository->business($stored['business_id']);
    $assert($stored['changed'] && $business['status'] === 'review', 'New source release requeued a terminal review.');
    $error = $f->database->pdo->query('SELECT last_error_code,last_error_message FROM businesses WHERE id='.$stored['business_id'])->fetch(PDO::FETCH_ASSOC);
    $assert($error === ['last_error_code' => 'review_required', 'last_error_message' => 'Retain review reason.'], 'Review reason was erased by source refresh.');
};
$tests['same snapshot rebuilt at another time retains its cursor and a new snapshot starts a fresh scan'] = static function () use ($fixture, $assert): void {
    $f = $fixture(3);
    $assert($f->run(1)['imported'] === 1, 'Initial source was not imported.');
    $pdo = new PDO('sqlite:'.$f->snapshot);
    $pdo->exec("INSERT INTO metadata VALUES ('prepared_at','2030-01-01T00:00:00Z')");
    $pdo = null;
    $source = $f->source();
    $assert($source->progress()['scanned'] === 1, 'Changing preparation time reset the source cursor.');
    unset($source);
    $pdo = new PDO('sqlite:'.$f->snapshot);
    $pdo->exec("UPDATE metadata SET value='124' WHERE key='snapshot_id'");
    $pdo = null;
    $assert($f->source()->progress()['scanned'] === 0, 'A new source snapshot reused the old cursor.');
    $report = $f->run(3);
    $assert($report['imported'] === 2 && $report['foursquare_progress']['scanned'] === 3, 'New snapshot missed remaining rows or reimported unchanged success.');
};
$tests['429 preflight stops immediately and resumes the persisted candidate on the next run'] = static function () use ($fixture, $assert): void {
    $f = $fixture(3);
    $f->api->check = static function (): array {
        throw new ApiException('Rate limited.', 429, 'rate_limited', retryable: true);
    };
    try {
        $f->run(3);
        throw new LogicException('429 did not stop the run.');
    } catch (ApiException $error) {
        $assert($error->status === 429 && count($f->api->checks) === 1, 'Rate limit checked further source rows.');
    }
    $assert($f->source()->progress()['scanned'] === 1 && $f->source()->progress()['pending'] === 1, '429 lost the already persisted candidate.');
    $f->api->check = null;
    $assert($f->run(3)['imported'] === 3, '429 recovery lost candidates or exceeded the source cursor.');
};
$tests['actual API transport keeps dry-run flag and decodes review responses as nonretryable'] = static function () use ($assert): void {
    $http = new class implements HttpClientInterface
    {
        public array $requests = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
        {
            $this->requests[] = ['url' => $url, 'body' => $body];

            return str_ends_with($url, '/token')
                ? new HttpResponse(200, [], Json::encode(['access_token' => 'fixture-token', 'expires_in' => 3600]))
                : new HttpResponse(409, [], Json::encode(['success' => false, 'message' => 'Review preview.',
                    'errors' => ['review_required' => ['Conflicting place match.']], 'data' => ['review_id' => null]]));
        }
    };
    $tokens = new OAuthTokenProvider($http, 'https://fixture.example.org/token', 'fixture-client', 'fixture-secret', 5, 'FoursquareTest');
    $api = new SveeveeApiClient($http, $tokens, 'https://fixture.example.org/import', 5, 0, 0, 'FoursquareTest');
    try {
        $api->checkDuplicate(['name' => 'Fixture', 'dry_run' => true,
            'source' => ['provider' => 'foursquare_places', 'id' => FoursquareFixture::id(1)], 'public_description' => 'Not a lookup field']);
        throw new LogicException('Expected review response.');
    } catch (ApiException $error) {
        $assert($error->status === 409 && $error->reason === 'review_required' && ! $error->retryable, 'Review response became a retryable API error.');
    }
    $payload = Json::decode($http->requests[1]['body']);
    $assert(count($http->requests) === 2 && $payload['dry_run'] === true && $payload['source']['provider'] === 'foursquare_places'
        && ! isset($payload['public_description']), 'Actual HTTP payload lost dry-run protection or source provenance.');
};
$tests['first location match sends source identity evidence without replacing existing contact values'] = static function () use ($fixture, $assert): void {
    $f = $fixture(1, static fn (array $row): array => array_replace($row, ['name' => 'Matching place', 'locality' => 'Haifa', 'address' => 'Main road 10']));
    $remote = ['id' => 91, 'name' => 'Matching place', 'address' => ['city' => 'Haifa', 'street' => 'Main road', 'number' => '10'],
        'phone' => '+97235559999', 'can_update' => true];
    $f->api->remote = $remote;
    $f->api->check = static fn (): array => ['matches' => [array_replace($remote, ['matched_on' => ['name', 'address']])]];
    $report = $f->run(1);
    $patch = $f->api->batches[0]['businesses'][0];
    $assert($report['updated'] === 1 && $report['failed'] === 0 && $patch['id'] === 91, 'Confirmed location failed to receive a source association.');
    $assert($patch['name'] === 'Matching place' && $patch['address'] === ['city' => 'Haifa', 'street' => 'Main road 10'], 'The patch used remote rather than original source address evidence.');
    $assert(! isset($patch['phone']) && $patch['website'] === 'https://same.example.org', 'Patch replaced an existing contact or omitted a missing one.');
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
        foreach (['places.sqlite', 'places.sqlite-journal', 'worker.log'] as $file) {
            if (is_file($directory.'/'.$file)) {
                unlink($directory.'/'.$file);
            }
        }
        rmdir($directory);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
    restore_error_handler();
}
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
