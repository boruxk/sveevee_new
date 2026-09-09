<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Research\DataGovCkanSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

final class CompaniesFixtureHttp implements HttpClientInterface
{
    public array $requests = [];

    public string $mode = '';

    public function __construct(public array $records) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $this->requests[] = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $filters = Json::decode($query['filters']);
        $records = array_values(array_filter($this->records, static fn (array $row): bool => in_array($row['שם עיר'] ?? null, (array) ($filters['שם עיר'] ?? []), true)
                && ($row['סטטוס חברה'] ?? null) === ($filters['סטטוס חברה'] ?? null)
        ));
        usort($records, static fn (array $a, array $b): int => (($a['מספר חברה'] ?? 0) <=> ($b['מספר חברה'] ?? 0)) ?: ($a['_id'] <=> $b['_id'])
        );
        $offset = (int) $query['offset'];
        $page = $this->mode === 'empty' ? [] : array_slice($records, $this->mode === 'repeat' ? 0 : $offset, (int) $query['limit']);
        if ($this->mode === 'schema' && $page !== []) {
            unset($page[0]['מטרת החברה']);
        }
        if ($this->mode === 'wrong_city' && $page !== []) {
            $page[0]['שם עיר'] = 'ירושלים';
        }
        $result = [
            'total' => count($records) + ($this->mode === 'changed_total' && $offset > 0 ? 1 : 0),
            'total_was_estimated' => $this->mode === 'estimated',
            'records' => $page,
        ];
        if ($this->mode === 'missing_total') {
            unset($result['total']);
        }

        return new HttpResponse(200, [], Json::encode($this->mode === 'api_error'
            ? ['success' => false, 'error' => ['message' => 'Invalid query']]
            : ['success' => true, 'result' => $result]));
    }
}

$databasePath = tempnam(sys_get_temp_dir(), 'sveevee-companies-');
if ($databasePath === false) {
    throw new RuntimeException('Cannot create temporary test database.');
}
$database = new Database($databasePath);
$repository = new WorkerRepository($database, new BusinessNormalizer(new OpeningHoursParser, ['Haifa', 'Jerusalem', 'Tel Aviv']), new BusinessMerger);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$dataset = [
    'profile' => 'israel_companies',
    'resource_id' => 'f004176c-b85f-4542-8901-7b3176f9a054',
    'city_names' => ['Haifa' => ['חיפה'], 'Jerusalem' => ['ירושלים'], 'Tel Aviv' => ['תל אביב - יפו', 'תל  אביב']],
];
$row = static fn (int $id, string $name, string $city = 'חיפה'): array => [
    '_id' => $id, 'מספר חברה' => 510000000 + $id, 'שם חברה' => $name, 'שם באנגלית' => '',
    'סטטוס חברה' => 'פעילה', 'קוד סטטוס חברה' => 0, 'תאור חברה' => '',
    'מטרת החברה' => 'לעסוק בכל עיסוק חוקי', 'שם עיר' => $city, 'שם רחוב' => 'הרצל', 'מספר בית' => '10',
];
$makeSource = static fn (CompaniesFixtureHttp $http, array $overrides = []): DataGovCkanSource => new DataGovCkanSource(
    $overrides + ['min_interval_seconds' => 0, 'page_size' => 3, 'max_retries' => 0, 'datasets' => [$dataset]],
    $http, $repository, 'SveeveeTest/1.0',
);
$fixtures = [
    'food_catering.bakery' => $row(1, 'מאפיית בדיקה בעמ'),
    'food_catering.restaurants' => $row(2, 'מסעדת בדיקה בעמ'),
    'professionals.fast_food' => $row(3, 'פיצריית בדיקה בעמ'),
    'food_catering.cafes' => $row(4, 'בית קפה בדיקה בעמ'),
    'professionals.catering' => $row(5, 'קייטרינג בדיקה בעמ'),
    'professionals.grocery_food' => $row(6, 'מכולת בדיקה בעמ'),
    'food_catering.meat_deli' => $row(7, 'אטליז בדיקה בעמ'),
    'food_catering.bars' => $row(8, 'פאב בדיקה בעמ'),
    'professionals.venues' => $row(9, 'אולם אירועים בדיקה בעמ'),
    'travel_leisure.hotels_guesthouses' => $row(10, 'מלון בדיקה בעמ'),
];
$tests = [];
$tests['ten categories share one scoped city scan and canonical addresses'] = static function () use ($fixtures, $row, $makeSource, $assert): void {
    $http = new CompaniesFixtureHttp([...array_values($fixtures), $row(11, 'מסעדת ירושלים בעמ', 'ירושלים')]);
    $source = $makeSource($http);
    foreach ($fixtures as $category => $fixture) {
        $businesses = iterator_to_array($source->research(new ResearchTarget('Haifa', $category), 100));
        $assert(count($businesses) === 1 && $businesses[0]['name'] === $fixture['שם חברה'], 'Unexpected classification for '.$category);
        $assert($businesses[0]['address'] === ['street' => 'הרצל', 'number' => '10', 'city' => 'Haifa'], 'Address must use the canonical catalog city.');
        $assert(str_contains($businesses[0]['public_description'], 'מרשם החברות'), 'Description must identify a registered company address.');
        foreach (['phone', 'contact_email', 'opening_hours', 'website', 'service_areas'] as $field) {
            $assert(! isset($businesses[0][$field]), 'The register does not establish '.$field.'.');
        }
    }
    $assert(count($http->requests) === 4, 'Expected four pages total across ten categories.');
    foreach ($http->requests as $index => $url) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $assert(Json::decode($query['filters']) === ['שם עיר' => ['חיפה'], 'סטטוס חברה' => 'פעילה'], 'City and active status must be applied on the server.');
        $assert($query['sort'] === 'מספר חברה asc, _id asc', 'Pagination must use a deterministic sort.');
        $assert($query['total_estimation_threshold'] === '0' && $query['offset'] === (string) ($index * 3), 'Expected exact counts and advancing offsets.');
        $assert(str_contains($query['fields'], 'מספר חברה'), 'Fetch the documented registry fields.');
    }
    $businesses = iterator_to_array($source->research(new ResearchTarget('Jerusalem', 'food_catering.restaurants'), 100));
    $assert(count($businesses) === 1 && $businesses[0]['address']['city'] === 'Jerusalem', 'Second city reused the first city cache.');
    $assert(count($http->requests) === 5, 'Second city must issue its own scoped query.');
};
$tests['unknown city, unsupported category and neighborhoods never request the whole register'] = static function () use ($makeSource, $assert): void {
    $http = new CompaniesFixtureHttp([]);
    $source = $makeSource($http);
    foreach ([new ResearchTarget('Unknown', 'food_catering.restaurants'), new ResearchTarget('Haifa', 'professionals.electricians'), new ResearchTarget('Haifa', 'food_catering.restaurants', 'Unknown')] as $target) {
        $assert(iterator_to_array($source->research($target, 100)) === [], 'Unsupported target was accepted.');
    }
    $assert($http->requests === [], 'Unsupported target must not make a national unfiltered request.');
};
$tests['inactive, unclassified and incomplete companies cannot become business pages'] = static function () use ($row, $makeSource, $assert): void {
    $base = $row(20, 'מסעדת בדיקה בעמ');
    $invalid = [
        ['סטטוס חברה' => 'מחוקה'], ['קוד סטטוס חברה' => 19], ['מספר חברה' => 'bad'],
        ['מספר חברה' => '0'], ['שם חברה' => ''], ['שם רחוב' => ''],
        ['שם חברה' => 'אחזקות בדיקה בעמ'],
    ];
    $records = [];
    foreach ($invalid as $index => $change) {
        $records[] = array_replace($base, ['_id' => 20 + $index], $change);
    }
    $source = $makeSource(new CompaniesFixtureHttp($records));
    $assert(iterator_to_array($source->research(new ResearchTarget('Haifa', 'food_catering.restaurants'), 100)) === [], 'Invalid or unrelated company was imported.');
};
$tests['house numbers stay optional and exact city aliases survive the query'] = static function () use ($row, $makeSource, $assert): void {
    $http = new CompaniesFixtureHttp([array_replace($row(40, 'מסעדת בדיקה בעמ', 'תל  אביב'), ['מספר בית' => '0'])]);
    $businesses = iterator_to_array($makeSource($http)->research(new ResearchTarget('Tel Aviv', 'food_catering.restaurants'), 100));
    $assert(count($businesses) === 1 && $businesses[0]['address'] === ['street' => 'הרצל', 'city' => 'Tel Aviv'], 'Registry alias or missing house number was lost.');
    parse_str((string) parse_url($http->requests[0], PHP_URL_QUERY), $query);
    $assert(in_array('תל  אביב', Json::decode($query['filters'])['שם עיר'], true), 'Exact registry alias whitespace must survive server-side filters.');
};
$tests['provenance uses company number and unchanged rows do not consume the candidate limit'] = static function () use ($row, $makeSource, $assert, $database): void {
    $target = new ResearchTarget('Haifa', 'food_catering.restaurants');
    $original = $row(50, 'מסעדת ראשונה בעמ');
    $first = iterator_to_array($makeSource(new CompaniesFixtureHttp([$original]))->research($target, 1))[0];
    parse_str((string) parse_url($first['source_url'], PHP_URL_QUERY), $query);
    $assert(Json::decode($query['filters']) === ['מספר חברה' => 510000050], 'Provenance must identify the stable company number.');
    $database->pdo->prepare('INSERT INTO researched_urls (adapter, url_hash, source_url, status, checked_at, raw_hash) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['data_gov_ckan', hash('sha256', $first['source_url']), $first['source_url'], 'success', Clock::now(), SourceFingerprint::hash($first)]);
    $renumbered = array_replace($original, ['_id' => 900]);
    $businesses = iterator_to_array($makeSource(new CompaniesFixtureHttp([$renumbered, $row(51, 'מסעדת שנייה בעמ')]))->research($target, 1));
    $assert(count($businesses) === 1 && $businesses[0]['name'] === 'מסעדת שנייה בעמ', 'An unchanged company consumed the limit after row renumbering.');
    $changed = array_replace($renumbered, ['שם רחוב' => 'הנביאים']);
    $businesses = iterator_to_array($makeSource(new CompaniesFixtureHttp([$changed]))->research($target, 1));
    $assert(count($businesses) === 1 && $businesses[0]['address']['street'] === 'הנביאים', 'A changed company must be eligible before the refresh age.');
};
$tests['successful empty cities return zero and are cached across categories'] = static function () use ($makeSource, $assert): void {
    $http = new CompaniesFixtureHttp([]);
    $source = $makeSource($http);
    foreach (['food_catering.restaurants', 'food_catering.bakery'] as $category) {
        $assert(iterator_to_array($source->research(new ResearchTarget('Haifa', $category), 100)) === [], 'Empty city returned records.');
    }
    $assert(count($http->requests) === 1, 'An empty city should not be refetched for each category.');
};
foreach (['api_error', 'empty', 'repeat', 'estimated', 'missing_total', 'changed_total', 'schema'] as $mode) {
    $tests['API failure '.$mode.' is explicit instead of appearing as zero'] = static function () use ($row, $makeSource, $assert, $mode): void {
        $http = new CompaniesFixtureHttp([$row(60, 'מסעדת ראשונה בעמ'), $row(61, 'מסעדת שנייה בעמ')]);
        $http->mode = $mode;
        $failed = false;
        try {
            iterator_to_array($makeSource($http, ['page_size' => 1])->research(new ResearchTarget('Haifa', 'food_catering.restaurants'), 100));
        } catch (RuntimeException) {
            $failed = true;
        }
        $assert($failed, $mode.' was treated as successful research.');
    };
}
$tests['city scan bound counts all registry rows before category filtering'] = static function () use ($row, $makeSource, $assert): void {
    $http = new CompaniesFixtureHttp([$row(70, 'אחזקות ראשונה בעמ'), $row(71, 'אחזקות שנייה בעמ')]);
    $failed = false;
    try {
        iterator_to_array($makeSource($http, ['max_records_per_city' => 1])->research(new ResearchTarget('Haifa', 'food_catering.restaurants'), 100));
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'max_records_per_city');
    }
    $assert($failed && count($http->requests) === 1, 'A capped city must be reported as incomplete, even when all rows are unclassified.');
};
$tests['record city is verified even if an upstream response ignores the filter'] = static function () use ($row, $makeSource, $assert): void {
    $http = new CompaniesFixtureHttp([$row(80, 'מסעדת בדיקה בעמ')]);
    $http->mode = 'wrong_city';
    $assert(iterator_to_array($makeSource($http)->research(new ResearchTarget('Haifa', 'food_catering.restaurants'), 100)) === [], 'An unrelated city was relabeled as the target city.');
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
$testCount = count($tests);
unset($database, $repository, $makeSource, $tests, $test);
gc_collect_cycles();
foreach ([$databasePath.'-wal', $databasePath.'-shm', $databasePath] as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}
fwrite(STDOUT, $testCount.' tests, '.$failures." failures\n");
exit($failures === 0 ? 0 : 1);
