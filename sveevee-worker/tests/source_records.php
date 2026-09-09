<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\BudgetedHttpClient;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Http\SourceRequestBudgetExceeded;
use Sveevee\Worker\Research\DataGovCkanSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;

const FULL_COMPANIES = 'f004176c-b85f-4542-8901-7b3176f9a054';
const FULL_BEERSHEBA = '7d4c61e2-2416-453e-8efb-bd02ec89db35';

final class FullRecordsHttp implements HttpClientInterface
{
    public array $requests = [];

    public ?int $failOffset = null;

    public bool $repeat = false;

    public function __construct(public array $resources) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->requests[] = $query;
        $offset = (int) $query['offset'];
        if ($offset === $this->failOffset) {
            return new HttpResponse(503, [], '{}');
        }
        $all = $this->resources[$query['resource_id']] ?? [];

        return new HttpResponse(200, [], Json::encode(['success' => true, 'result' => [
            'records' => array_slice($all, $this->repeat ? 0 : $offset, (int) $query['limit']),
            'total' => count($all), 'total_was_estimated' => false,
        ]]));
    }
}

final class FullRecordsFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public function __construct(string $path = ':memory:')
    {
        $this->database = new Database($path);
        $this->repository = new WorkerRepository($this->database, new BusinessNormalizer(new OpeningHoursParser, ['Haifa', 'Beersheba']), new BusinessMerger);
        $this->database->pdo->exec('CREATE TABLE IF NOT EXISTS test_durable_receipts (id TEXT PRIMARY KEY, raw TEXT NOT NULL)');
    }

    public function source(HttpClientInterface $http, array $options = []): DataGovCkanSource
    {
        return new DataGovCkanSource($options + [
            'import_mode' => 'all_records', 'page_size' => 10, 'min_interval_seconds' => 0, 'max_retries' => 0,
            'datasets' => [['profile' => 'israel_companies', 'resource_id' => FULL_COMPANIES, 'city_names' => ['Haifa' => ['חיפה']]]],
        ], $http, $this->repository, 'SveeveeTest/1.0');
    }

    public function consume(DataGovCkanSource $source, int $limit = PHP_INT_MAX): array
    {
        $rows = [];
        foreach ($source->research(ResearchTarget::sourceAll('data_gov_ckan'), $limit) as $raw) {
            // A durable decision precedes ACK, just as production stores a candidate or rejection.
            $this->database->pdo->prepare('INSERT OR REPLACE INTO test_durable_receipts (id, raw) VALUES (?, ?)')
                ->execute([$raw['source_url'], Json::encode($raw)]);
            $rows[] = $raw;
            $source->acknowledge($raw);
        }

        return $rows;
    }

    public function state(string $scope = FULL_COMPANIES): array
    {
        $query = $this->database->pdo->prepare('SELECT * FROM source_record_scopes WHERE scope_key = ?');
        $query->execute([$scope]);

        return $query->fetch() ?: [];
    }

    public function queued(): int
    {
        return (int) $this->database->pdo->query('SELECT COUNT(*) FROM source_record_queue')->fetchColumn();
    }
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (Closure $work, string $message) use ($assert): void {
    try {
        $work();
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), $message), 'Unexpected error: '.$error->getMessage());

        return;
    }
    throw new RuntimeException('Expected failure containing '.$message);
};
$company = static fn (int $id): array => [
    '_id' => $id, 'מספר חברה' => 510000000 + $id, 'שם חברה' => 'עסק בדיקה '.$id, 'שם באנגלית' => '',
    'סטטוס חברה' => 'מחוקה', 'קוד סטטוס חברה' => 9, 'תאור חברה' => '', 'מטרת החברה' => 'לעסוק בכל עיסוק חוקי',
    'שם עיר' => 'יישוב שאינו בקטלוג', 'שם רחוב' => null, 'מספר בית' => null,
];
$tests = [];

$tests['full registry keeps inactive companies, unknown categories and raw cities without legacy limits'] = static function () use ($assert, $company): void {
    $fixture = new FullRecordsFixture;
    $http = new FullRecordsHttp([FULL_COMPANIES => array_map($company, range(1, 25))]);
    $rows = $fixture->consume($fixture->source($http, ['max_records_per_dataset' => 1, 'max_records_per_city' => 1]));
    $assert(count($rows) === 25 && count($http->requests) === 3, 'Full data was filtered or exceeded bounded page requests.');
    foreach ($http->requests as $query) {
        $assert(! isset($query['filters']) && ! isset($query['fields']) && $query['sort'] === '_id asc' && (int) $query['limit'] <= 10, 'Full search must not restrict cities, status, fields or categories.');
    }
    $assert($rows[0]['category_key'] === null && $rows[0]['address']['city'] === 'יישוב שאינו בקטלוג' && $rows[0]['address']['street'] === null, 'Unknown fields were invented or rejected.');
    $assert(! isset($rows[0]['public_description'], $rows[0]['service_areas']) && $rows[0]['source_metadata']['original_record'] === $company(1), 'Full source must retain facts without fabricated copy.');
    $assert($rows[0]['source_metadata']['source_id'] === FULL_COMPANIES.':510000001', 'Registry identity must use company number.');
    parse_str((string) parse_url($rows[0]['source_url'], PHP_URL_QUERY), $query);
    $assert(Json::decode($query['filters']) === ['מספר חברה' => 510000001], 'Legacy stable record URL changed.');
    $assert($fixture->queued() === 0 && (int) $fixture->state()['consumed_offset'] === 25, 'Consumed raw JSON was not pruned.');
};

$tests['partial page survives reopening and drains before another request'] = static function () use ($assert, $company): void {
    $path = tempnam(sys_get_temp_dir(), 'sveevee-full-records-');
    try {
        $fixture = new FullRecordsFixture($path);
        $http = new FullRecordsHttp([FULL_COMPANIES => array_map($company, range(1, 12))]);
        $first = $fixture->consume($fixture->source($http), 3);
        $assert(count($first) === 3 && $fixture->queued() === 7 && (int) $fixture->state()['next_offset'] === 10, 'Whole page was not saved before yield.');
        unset($fixture);
        $fixture = new FullRecordsFixture($path);
        $http->requests = [];
        $second = $fixture->consume($fixture->source($http), 7);
        $assert(count($second) === 7 && $http->requests === [] && $fixture->queued() === 0, 'Cache tail was redownloaded or discarded.');
        $last = $fixture->consume($fixture->source($http));
        $assert(count($last) === 2 && (int) $http->requests[0]['offset'] === 10, 'Resume offset is wrong.');
        $assert($fixture->database->pdo->query('SELECT completed_at FROM source_record_scans')->fetchColumn() !== null, 'Last quota ACK did not complete the scan.');
        unset($fixture);
    } finally {
        @unlink($path);
    }
};

$tests['ten request budget persists offset and resumes with a fresh budget'] = static function () use ($assert, $company): void {
    $fixture = new FullRecordsFixture;
    $http = new FullRecordsHttp([FULL_COMPANIES => array_map($company, range(1, 125))]);
    try {
        $fixture->consume($fixture->source(new BudgetedHttpClient($http, 10)));
        throw new RuntimeException('Budget was not enforced.');
    } catch (SourceRequestBudgetExceeded) {
        $assert(count($http->requests) === 10 && (int) $fixture->state()['consumed_offset'] === 100, 'First budget was exceeded or progress lost.');
    }
    $http->requests = [];
    $rows = $fixture->consume($fixture->source(new BudgetedHttpClient($http, 10)));
    $assert(count($rows) === 25 && count($http->requests) === 3 && (int) $http->requests[0]['offset'] === 100, 'Fresh run did not continue the same dataset.');
};

$tests['changed totals retain committed offset and a shrunk dataset can finish'] = static function () use ($assert, $company): void {
    foreach ([8, 23] as $newTotal) {
        $fixture = new FullRecordsFixture;
        $http = new FullRecordsHttp([FULL_COMPANIES => array_map($company, range(1, 20))]);
        $fixture->consume($fixture->source($http), 10);
        $http->resources[FULL_COMPANIES] = array_map($company, range(1, $newTotal));
        $http->requests = [];
        $rows = $fixture->consume($fixture->source($http));
        $assert((int) $http->requests[0]['offset'] === 10 && count($rows) === max(0, $newTotal - 10), 'Mutable total restarted the scan.');
        $assert((int) $fixture->state()['complete'] === 1 && (int) $fixture->state()['expected_total'] === $newTotal, 'Updated total was not persisted.');
    }
};

$tests['two resources finish in order before the global refresh cycle'] = static function () use ($assert, $company): void {
    $fixture = new FullRecordsFixture;
    $beer = ['_id' => 1, 'שם עסק' => 'מוסך כלשהו', 'שם רחוב' => null, 'סטטוס' => 0, 'תאריך תוקף' => '2001-01-01'];
    $http = new FullRecordsHttp([FULL_BEERSHEBA => [$beer], FULL_COMPANIES => [$company(1), $company(2)]]);
    $options = ['datasets' => [
        ['profile' => 'beer_sheva_business_licenses', 'resource_id' => FULL_BEERSHEBA, 'city' => 'Beersheba'],
        ['profile' => 'israel_companies', 'resource_id' => FULL_COMPANIES],
    ]];
    $first = $fixture->consume($fixture->source($http, $options), 1);
    $assert($first[0]['source_metadata']['source_id'] === FULL_BEERSHEBA.':1' && $first[0]['category_key'] === null, 'Expired non-food Beersheba row was excluded.');
    $http->requests = [];
    $second = $fixture->consume($fixture->source($http, $options));
    $assert(count($second) === 2 && $http->requests[0]['resource_id'] === FULL_COMPANIES, 'First resource starved the national register.');
    $http->requests = [];
    $assert($fixture->consume($fixture->source($http, $options)) === [] && $http->requests === [], 'Finished cycle refreshed before its interval.');
    $fixture->database->pdo->exec("UPDATE source_record_scans SET completed_at = '2000-01-01T00:00:00+00:00'");
    $fixture->consume($fixture->source($http, $options), 1);
    $assert($http->requests[0]['resource_id'] === FULL_BEERSHEBA && (int) $http->requests[0]['offset'] === 0, 'A finished full cycle did not refresh from its first resource.');
};

$tests['missing required values stay visible for durable pipeline rejection'] = static function () use ($assert, $company): void {
    $fixture = new FullRecordsFixture;
    $invalid = $company(1);
    $invalid['שם חברה'] = null;
    $invalid['מספר חברה'] = 'invalid';
    $rows = $fixture->consume($fixture->source(new FullRecordsHttp([FULL_COMPANIES => [$invalid]])));
    $assert(count($rows) === 1 && $rows[0]['name'] === null && $rows[0]['source_metadata']['source_id'] === null, 'Invalid identity was skipped or invented.');
    parse_str((string) parse_url($rows[0]['source_url'], PHP_URL_QUERY), $query);
    $assert(Json::decode($query['filters']) === ['_id' => 1], 'Invalid business must retain an auditable datastore row link.');
};

$tests['source refuses to advance without an acknowledgement and safely replays that row'] = static function () use ($assert, $throws, $company): void {
    $fixture = new FullRecordsFixture;
    $http = new FullRecordsHttp([FULL_COMPANIES => [$company(1), $company(2)]]);
    $source = $fixture->source($http);
    $iterator = $source->research(ResearchTarget::sourceAll('data_gov_ckan'), 2);
    $iterator->rewind();
    $first = $iterator->current();
    $throws(static fn () => $iterator->next(), 'durably acknowledged');
    $assert((int) $fixture->state()['consumed_offset'] === 0 && $fixture->queued() === 2, 'Unacknowledged row was skipped.');
    $http->requests = [];
    $rows = $fixture->consume($fixture->source($http));
    $assert(count($rows) === 2 && $rows[0]['source_url'] === $first['source_url'] && $http->requests === [], 'Unacknowledged row did not replay from disk.');
};

$tests['HTTP failure does not retry or lose the next offset'] = static function () use ($assert, $throws, $company): void {
    $fixture = new FullRecordsFixture;
    $http = new FullRecordsHttp([FULL_COMPANIES => array_map($company, range(1, 15))]);
    $http->failOffset = 10;
    $throws(static fn () => $fixture->consume($fixture->source($http)), 'HTTP 503');
    $assert(count($http->requests) === 2 && (int) $fixture->state()['consumed_offset'] === 10 && $fixture->queued() === 0, 'Failure retried or reset its scan.');
    $http->requests = [];
    $http->failOffset = null;
    $rows = $fixture->consume($fixture->source($http));
    $assert(count($rows) === 5 && (int) $http->requests[0]['offset'] === 10, 'Retry did not use the failed page offset.');
};

$tests['repeated page and full resource guard preserve committed progress'] = static function () use ($assert, $throws, $company): void {
    $fixture = new FullRecordsFixture;
    $http = new FullRecordsHttp([FULL_COMPANIES => array_map($company, range(1, 20))]);
    $fixture->consume($fixture->source($http), 10);
    $http->repeat = true;
    $throws(static fn () => $fixture->consume($fixture->source($http)), 'strictly increasing');
    $assert((int) $fixture->state()['consumed_offset'] === 10 && $fixture->queued() === 0, 'Bad pagination reset or polluted the queue.');
    $http->repeat = false;
    $throws(static fn () => $fixture->consume($fixture->source($http, ['max_records_per_full_dataset' => 15])), 'configured bound');
    $assert((int) $fixture->state()['consumed_offset'] === 10, 'Total safety bound silently truncated progress.');
};

$tests['cursor prevents out-of-order and stale-cycle acknowledgements'] = static function () use ($assert, $throws, $company): void {
    $fixture = new FullRecordsFixture;
    $cursor = $fixture->repository->sourceRecordCursor();
    $cursor->open('test', 'test', ['first', 'second'], 1);
    $cursor->append('test', 'first', 0, 2, [['id' => '1', 'record' => $company(1)], ['id' => '2', 'record' => $company(2)]], '2026-09-01T00:00:00+00:00', 10000);
    $throws(static fn () => $cursor->acknowledge('test', 'first', 2, 1), 'in order');
    $assert(count($cursor->pending('test', 'first')) === 2, 'Out-of-order ACK removed rows.');
    $cursor->acknowledge('test', 'first', 1, 1);
    $cursor->acknowledge('test', 'first', 2, 1);
    $assert($cursor->currentScope('test') === 'second', 'Next resource was not selected on final ACK.');
    $cursor->setTotal('test', 'second', 0);
    $assert($cursor->currentScope('test') === null, 'Empty last resource did not complete the cycle.');
    $fixture->database->pdo->exec("UPDATE source_record_scans SET completed_at = '2000-01-01T00:00:00+00:00'");
    $cursor->open('test', 'test', ['first', 'second'], 1);
    $throws(static fn () => $cursor->acknowledge('test', 'first', 1, 1), 'different source scan cycle');
    $assert((int) $cursor->state('test', 'first')['cycle'] === 2, 'New cycle was not versioned.');
};

$tests['page cache bound rolls back the entire page'] = static function () use ($assert, $throws, $company): void {
    $fixture = new FullRecordsFixture;
    $http = new FullRecordsHttp([FULL_COMPANIES => [$company(1), $company(2)]]);
    $throws(static fn () => $fixture->consume($fixture->source($http, ['max_cache_bytes' => 1])), 'max_cache_bytes');
    $assert($fixture->queued() === 0 && (int) $fixture->state()['next_offset'] === 0, 'Oversized page was partially committed.');
};

$tests['source schema drift fails before a malformed page can become durable'] = static function () use ($assert, $throws, $company): void {
    foreach (['שם חברה', 'מספר חברה'] as $field) {
        $fixture = new FullRecordsFixture;
        $row = $company(1);
        unset($row[$field]);
        $http = new FullRecordsHttp([FULL_COMPANIES => [$row]]);
        $throws(static fn () => $fixture->consume($fixture->source($http)), 'missing expected column');
        $assert($fixture->queued() === 0 && (int) $fixture->state()['next_offset'] === 0, 'Schema drift was acknowledged as an individual missing value.');
        $http->resources[FULL_COMPANIES] = [$company(1)];
        $assert(count($fixture->consume($fixture->source($http))) === 1, 'Corrected source page could not be retried.');
    }
};

$tests['Beersheba retains exact legacy license-field category mapping'] = static function () use ($assert): void {
    foreach (['תאור רישיון', 'תיאור רישיון'] as $field) {
        $fixture = new FullRecordsFixture;
        $record = ['_id' => 1, 'שם עסק' => 'עסק כלשהו', $field => 'מסעדה', 'סטטוס' => 0];
        $http = new FullRecordsHttp([FULL_BEERSHEBA => [$record]]);
        $rows = $fixture->consume($fixture->source($http, ['datasets' => [
            ['profile' => 'beer_sheva_business_licenses', 'resource_id' => FULL_BEERSHEBA],
        ]]));
        $assert(count($rows) === 1 && $rows[0]['category_key'] === 'food_catering.restaurants', 'License-only category mapping was lost.');
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, '[PASS] '.$name.PHP_EOL);
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, '[FAIL] '.$name.': '.$error->getMessage().PHP_EOL);
    }
}
fwrite(STDOUT, count($tests).' tests, '.$failures.' failures'.PHP_EOL);
exit($failures > 0 ? 1 : 0);
