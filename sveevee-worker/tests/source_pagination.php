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
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Research\TelAvivBusinessLicenseSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;

final class PaginationFixtureHttp implements HttpClientInterface
{
    public array $requests = [];

    public ?int $failOffset = null;

    public bool $repeat = false;

    public function __construct(public array $records) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->requests[] = $query;
        if (isset($query['returnCountOnly'])) {
            return new HttpResponse(200, [], Json::encode(['count' => count($this->records)]));
        }
        $ckan = isset($query['resource_id']);
        $offset = (int) $query[$ckan ? 'offset' : 'resultOffset'];
        if ($offset === $this->failOffset) {
            return new HttpResponse(503, [], '{}');
        }
        $page = array_slice($this->records, $this->repeat ? 0 : $offset, (int) $query[$ckan ? 'limit' : 'resultRecordCount']);

        return new HttpResponse(200, [], Json::encode($ckan
            ? ['success' => true, 'result' => ['total' => count($this->records), 'total_was_estimated' => false, 'records' => $page]]
            : ['features' => array_map(static fn (array $row): array => ['attributes' => $row], $page)]));
    }
}

final class PaginationFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public readonly BusinessNormalizer $normalizer;

    public function __construct(string $path = ':memory:')
    {
        $this->database = new Database($path);
        $this->normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa', 'Tel Aviv']);
        $this->repository = new WorkerRepository($this->database, $this->normalizer, new BusinessMerger);
    }

    public function ckan(HttpClientInterface $http, array $options = []): DataGovCkanSource
    {
        return new DataGovCkanSource($options + [
            'page_size' => 10, 'min_interval_seconds' => 0, 'max_retries' => 0,
            'datasets' => [['profile' => 'israel_companies', 'resource_id' => 'companies', 'city_names' => ['Haifa' => ['חיפה']]]],
        ], $http, $this->repository, 'SveeveeTest/1.0');
    }

    public function tel(HttpClientInterface $http, array $options = []): TelAvivBusinessLicenseSource
    {
        return new TelAvivBusinessLicenseSource($options + [
            'page_size' => 10, 'min_interval_seconds' => 0, 'max_retries' => 0,
        ], $http, $this->repository, 'SveeveeTest/1.0');
    }

    public function store(SourceAdapterInterface $source, ResearchTarget $target, int $limit = 1000): array
    {
        $rows = [];
        foreach ($source->research($target, $limit) as $row) {
            $this->repository->upsertCandidate($this->normalizer->normalize($row, $target, $source->name()));
            $rows[] = $row;
        }

        return $rows;
    }

    public function state(): array
    {
        return $this->database->pdo->query('SELECT * FROM source_page_scopes')->fetch() ?: [];
    }
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$budgetEnds = static function (Closure $work, int $limit) use ($assert): void {
    try {
        $work();
    } catch (SourceRequestBudgetExceeded $error) {
        $assert($error->limit === $limit, 'Wrong budget limit.');

        return;
    }
    throw new RuntimeException('Expected the original typed HTTP-budget exception.');
};
$company = static fn (int $id, string $name = 'מסעדת בדיקה'): array => [
    '_id' => $id, 'מספר חברה' => 510000000 + $id, 'שם חברה' => $name.' '.$id, 'שם באנגלית' => '',
    'סטטוס חברה' => 'פעילה', 'קוד סטטוס חברה' => 0, 'תאור חברה' => '',
    'מטרת החברה' => 'לעסוק בכל עיסוק חוקי', 'שם עיר' => 'חיפה', 'שם רחוב' => 'הרצל', 'מספר בית' => (string) $id,
];
$license = static fn (int $id): array => [
    'oid_rishayon' => $id, 'ms_esek_rashi' => 1000 + $id, 'ms_esek_mishne' => 0,
    't_shem_esek' => 'מסעדה '.$id, 't_hesber_mahut_esek' => '',
    'taarich_tokef' => '2999-01-01 00:00:00.0000000', 'mahuiot' => '402100', 'shem_rechov' => 'דיזנגוף '.$id,
];
$haifa = new ResearchTarget('Haifa', 'food_catering.restaurants');
$telAviv = new ResearchTarget('Tel Aviv', 'food_catering.restaurants');
$tests = [];

$tests['CKAN survives a database reopen and resumes at offset 100 within a fresh ten-request budget'] = static function () use ($assert, $budgetEnds, $company, $haifa): void {
    $path = tempnam(sys_get_temp_dir(), 'sveevee-source-pages-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary database.');
    }
    try {
        $fixture = new PaginationFixture($path);
        $http = new PaginationFixtureHttp(array_map($company, range(1, 150)));
        $budget = new BudgetedHttpClient($http, 10);
        $budgetEnds(static fn () => $fixture->store($fixture->ckan($budget, ['page_size' => 1000]), $haifa), 10);
        $assert(count($http->requests) === 10 && (int) $fixture->state()['next_offset'] === 100, 'First run did not commit ten pages.');
        $assert((int) $fixture->state()['complete'] === 0, 'Budget exhaustion must not finish the scope.');
        foreach ($http->requests as $i => $query) {
            $assert((int) $query['limit'] === 10 && (int) $query['offset'] === $i * 10, 'Ten-row cap or stable advancing offset failed.');
        }
        unset($fixture);
        $fixture = new PaginationFixture($path);
        $http->requests = [];
        $rows = $fixture->store($fixture->ckan(new BudgetedHttpClient($http, 10)), $haifa);
        $assert(count($rows) === 50 && count($http->requests) === 5, 'Cached unchanged rows must not consume the research limit or be refetched.');
        $assert((int) $http->requests[0]['offset'] === 100 && (int) $fixture->state()['complete'] === 1, 'Second process restarted the completed pages.');
        $assert((int) $fixture->database->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 150, 'A raw page or candidate was lost.');
    } finally {
        unset($fixture, $budget);
        gc_collect_cycles();
        foreach ([$path.'-wal', $path.'-shm', $path] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
};

$tests['a mid-page limit retains every row and permits repository writes while streaming the cache'] = static function () use ($assert, $company, $haifa): void {
    $fixture = new PaginationFixture;
    $http = new PaginationFixtureHttp(array_map($company, range(1, 15)));
    $first = $fixture->store($fixture->ckan($http), $haifa, 1);
    $assert(count($first) === 1 && (int) $fixture->state()['next_offset'] === 10, 'Whole page must be durable before the first yield.');
    $assert((int) $fixture->database->pdo->query('SELECT COUNT(*) FROM source_page_rows')->fetchColumn() === 10, 'Unconsumed rows were dropped.');
    $http->requests = [];
    $rows = $fixture->store($fixture->ckan($http), $haifa);
    $assert(count($rows) === 14 && count($http->requests) === 1 && (int) $http->requests[0]['offset'] === 10, 'Remaining cached and remote rows were not imported exactly once.');
    $assert((int) $fixture->database->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 15, 'Concurrent cached reads and candidate transactions lost a business.');
};

$tests['other categories share cached eligible rows and ineligible JSON is not retained'] = static function () use ($assert, $budgetEnds, $company, $haifa): void {
    $fixture = new PaginationFixture;
    $records = array_map(static fn (int $id): array => $company($id, $id <= 10 ? 'מאפיית בדיקה' : ($id <= 100 ? 'אחזקות בדיקה' : 'מסעדת בדיקה')), range(1, 110));
    $http = new PaginationFixtureHttp($records);
    $budgetEnds(static fn () => iterator_to_array($fixture->ckan(new BudgetedHttpClient($http, 10))->research($haifa, 1000)), 10);
    $assert((int) $fixture->database->pdo->query('SELECT COUNT(*) FROM source_page_rows')->fetchColumn() === 100, 'Even skipped rows need stable pagination IDs.');
    $assert((int) $fixture->database->pdo->query('SELECT COUNT(record_json) FROM source_page_rows')->fetchColumn() === 10, 'Only relevant company rows should retain large JSON.');
    $http->requests = [];
    $bakeries = $fixture->store($fixture->ckan($http), new ResearchTarget('Haifa', 'food_catering.bakery'), 10);
    $assert(count($bakeries) === 10 && $http->requests === [], 'A different category lost its previously scanned rows.');
    $restaurants = $fixture->store($fixture->ckan($http), $haifa);
    $assert(count($restaurants) === 10 && (int) $http->requests[0]['offset'] === 100, 'Sparse categories restarted an already scanned city.');
};

$tests['Tel Aviv persists its count and resumes after nine data pages'] = static function () use ($assert, $budgetEnds, $license, $telAviv): void {
    $fixture = new PaginationFixture;
    $http = new PaginationFixtureHttp(array_map($license, range(1, 110)));
    $budgetEnds(static fn () => $fixture->store($fixture->tel(new BudgetedHttpClient($http, 10)), $telAviv), 10);
    $assert(count($http->requests) === 10 && (int) $fixture->state()['next_offset'] === 90, 'ArcGIS count must consume one of the ten requests.');
    $http->requests = [];
    $rows = $fixture->store($fixture->tel(new BudgetedHttpClient($http, 10)), $telAviv);
    $assert(count($rows) === 20 && count($http->requests) === 2 && (int) $http->requests[0]['resultOffset'] === 90, 'A later run repeated the count or completed pages.');
    $assert((int) $fixture->state()['complete'] === 1, 'Final license page did not complete the scope.');
};

$tests['a count-only Tel Aviv run resumes with its first data page'] = static function () use ($assert, $budgetEnds, $license, $telAviv): void {
    $fixture = new PaginationFixture;
    $http = new PaginationFixtureHttp([$license(1)]);
    $budgetEnds(static fn () => iterator_to_array($fixture->tel(new BudgetedHttpClient($http, 1))->research($telAviv, 1000)), 1);
    $assert((int) $fixture->state()['expected_total'] === 1 && (int) $fixture->state()['next_offset'] === 0, 'Count-only progress was not saved.');
    $http->requests = [];
    $rows = iterator_to_array($fixture->tel(new BudgetedHttpClient($http, 1))->research($telAviv, 1000));
    $assert(count($rows) === 1 && count($http->requests) === 1 && isset($http->requests[0]['resultOffset']), 'Saved count was fetched again.');
};

foreach (['ckan', 'tel'] as $kind) {
    $tests[$kind.' HTTP failure preserves pages without reporting a completed empty scan'] = static function () use ($assert, $company, $license, $haifa, $telAviv, $kind): void {
        $fixture = new PaginationFixture;
        $http = new PaginationFixtureHttp(array_map($kind === 'ckan' ? $company : $license, range(1, 15)));
        $target = $kind === 'ckan' ? $haifa : $telAviv;
        $http->failOffset = 10;
        try {
            $fixture->store($fixture->$kind($http), $target);
            throw new LogicException('Expected an HTTP failure.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'HTTP 503'), 'HTTP error was misclassified.');
        }
        $assert((int) $fixture->state()['next_offset'] === 10 && (int) $fixture->state()['complete'] === 0, 'HTTP failure discarded valid rows or completed the scope.');
        $http->failOffset = null;
        $http->requests = [];
        $rows = $fixture->store($fixture->$kind($http), $target);
        $assert(count($rows) === 5 && count($http->requests) === 1 && (int) $http->requests[0][$kind === 'ckan' ? 'offset' : 'resultOffset'] === 10, 'Retry did not resume the failed page.');
    };

    $tests[$kind.' repeated IDs discard the inconsistent scope and allow a fresh scan'] = static function () use ($assert, $budgetEnds, $company, $license, $haifa, $telAviv, $kind): void {
        $fixture = new PaginationFixture;
        $http = new PaginationFixtureHttp(array_map($kind === 'ckan' ? $company : $license, range(1, 20)));
        $target = $kind === 'ckan' ? $haifa : $telAviv;
        $budget = $kind === 'ckan' ? 1 : 2;
        $budgetEnds(static fn () => $fixture->store($fixture->$kind(new BudgetedHttpClient($http, $budget)), $target), $budget);
        $http->repeat = true;
        try {
            $fixture->store($fixture->$kind($http), $target);
            throw new LogicException('Expected repeated-row detection.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'repeated a row') && str_contains($error->getMessage(), 'discarded'), 'Inconsistent pages need an explicit reset audit.');
        }
        $assert($fixture->state() === [] && (int) $fixture->database->pdo->query('SELECT COUNT(*) FROM source_page_rows')->fetchColumn() === 0, 'Inconsistent cursor remained stuck at its failed offset.');
        $assert((int) $fixture->database->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 10, 'A cache reset deleted already validated businesses.');
        $http->repeat = false;
        $http->requests = [];
        $rows = $fixture->store($fixture->$kind($http), $target);
        $firstPage = $http->requests[$kind === 'ckan' ? 0 : 1];
        $assert((int) $firstPage[$kind === 'ckan' ? 'offset' : 'resultOffset'] === 0 && count($rows) === 10, 'Clean retry did not restart and preserve prior imports.');
    };
}

$tests['changed CKAN totals reset a partial snapshot without a permanent cursor failure'] = static function () use ($assert, $budgetEnds, $company, $haifa): void {
    $fixture = new PaginationFixture;
    $http = new PaginationFixtureHttp(array_map($company, range(1, 15)));
    $budgetEnds(static fn () => $fixture->store($fixture->ckan(new BudgetedHttpClient($http, 1)), $haifa), 1);
    $http->records[] = $company(16);
    try {
        $fixture->store($fixture->ckan($http), $haifa);
        throw new LogicException('Expected changed total detection.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'count changed') && str_contains($error->getMessage(), 'discarded'), 'Changed count did not explicitly invalidate its snapshot.');
    }
    $http->requests = [];
    $rows = $fixture->store($fixture->ckan($http), $haifa);
    $assert((int) $http->requests[0]['offset'] === 0 && count($rows) === 6 && (int) $fixture->state()['expected_total'] === 16, 'Later run could not recover from a changed count.');
};

$tests['cached source timestamps remain original and completed scopes refresh only after their TTL'] = static function () use ($assert, $company, $haifa): void {
    $fixture = new PaginationFixture;
    $http = new PaginationFixtureHttp([$company(1)]);
    iterator_to_array($fixture->ckan($http)->research($haifa, 1000));
    $original = '2026-01-02T03:04:05+00:00';
    $fixture->database->pdo->prepare('UPDATE source_page_rows SET checked_at = ?')->execute([$original]);
    $http->requests = [];
    $cached = iterator_to_array($fixture->ckan($http)->research($haifa, 1000));
    $assert($http->requests === [] && $cached[0]['source_checked_at'] === $original, 'Cache replay fabricated a fresh source check.');
    $fixture->database->pdo->exec("UPDATE source_page_scopes SET completed_at = '2000-01-01T00:00:00+00:00'");
    $http->records = [$company(2)];
    $fresh = iterator_to_array($fixture->ckan($http)->research($haifa, 1000));
    $assert(count($http->requests) === 1 && $fresh[0]['name'] === $company(2)['שם חברה'], 'Expired completed scope did not refresh.');
};

$tests['old incomplete scopes resume and successful empty scopes are cached'] = static function () use ($assert, $budgetEnds, $company, $haifa): void {
    $fixture = new PaginationFixture;
    $http = new PaginationFixtureHttp(array_map($company, range(1, 15)));
    $budgetEnds(static fn () => iterator_to_array($fixture->ckan(new BudgetedHttpClient($http, 1))->research($haifa, 1000)), 1);
    $fixture->database->pdo->exec("UPDATE source_page_scopes SET created_at = '2000-01-01', updated_at = '2000-01-01'");
    $http->requests = [];
    iterator_to_array($fixture->ckan($http)->research($haifa, 1000));
    $assert(count($http->requests) === 1 && (int) $http->requests[0]['offset'] === 10, 'Age must not restart incomplete pagination.');
    $emptyFixture = new PaginationFixture;
    $emptyHttp = new PaginationFixtureHttp([]);
    $assert(iterator_to_array($emptyFixture->ckan($emptyHttp)->research($haifa, 1000)) === [], 'Empty source emitted a record.');
    iterator_to_array($emptyFixture->ckan($emptyHttp)->research($haifa, 1000));
    $assert(count($emptyHttp->requests) === 1 && (int) $emptyFixture->state()['complete'] === 1, 'Verified empty scope should be shared across runs.');
};

$tests['cache byte and source record limits remain explicit and atomic'] = static function () use ($assert, $company, $haifa): void {
    foreach ([['max_cache_bytes' => 1], ['max_records_per_city' => 1]] as $options) {
        $fixture = new PaginationFixture;
        $http = new PaginationFixtureHttp([$company(1), $company(2)]);
        try {
            iterator_to_array($fixture->ckan($http, $options)->research($haifa, 1000));
            throw new LogicException('Expected an explicit configured size limit.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), array_key_first($options)), 'Bound failure lost its configured option.');
        }
        $assert((int) $fixture->database->pdo->query('SELECT COUNT(*) FROM source_page_rows')->fetchColumn() === 0, 'A failed page committed partial data.');
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}
fwrite(STDOUT, count($tests).' tests, '.$failures." failures\n");
exit($failures === 0 ? 0 : 1);
