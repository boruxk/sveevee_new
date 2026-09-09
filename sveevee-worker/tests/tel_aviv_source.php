<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Research\TelAvivBusinessLicenseSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

final class TelAvivFixtureHttp implements HttpClientInterface
{
    public array $requests = [];

    public bool $emptyPage = false;

    public bool $repeatPage = false;

    public bool $apiError = false;

    public function __construct(public array $records) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $this->requests[] = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $payload = $this->apiError ? ['error' => ['code' => 400, 'message' => 'Invalid query']] : (
            isset($query['returnCountOnly']) ? ['count' => count($this->records)] : [
                'features' => array_map(static fn (array $row): array => ['attributes' => $row], $this->emptyPage ? [] : array_slice(
                    $this->records,
                    $this->repeatPage ? 0 : (int) $query['resultOffset'],
                    (int) $query['resultRecordCount'],
                )),
            ]
        );

        return new HttpResponse(200, [], Json::encode($payload));
    }
}

$databasePath = tempnam(sys_get_temp_dir(), 'sveevee-tlv-');
if ($databasePath === false) {
    throw new RuntimeException('Cannot create temporary test database.');
}
$database = new Database($databasePath);
$repository = new WorkerRepository($database, new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv', 'Beersheba']), new BusinessMerger);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$row = static fn (int $id, string $code, string $name = 'עסק בדיקה', string $description = ''): array => [
    'oid_rishayon' => $id, 'ms_esek_rashi' => 1000 + $id, 'ms_esek_mishne' => 0,
    't_shem_esek' => $name, 't_hesber_mahut_esek' => $description,
    'taarich_tokef' => '2999-01-01 00:00:00.0000000', 'mahuiot' => $code, 'shem_rechov' => 'דיזנגוף',
];
$fixtures = [
    'food_catering.bakery' => $row(1, '406103', 'מאפיית בדיקה'),
    'food_catering.restaurants' => $row(2, '402100', 'מסעדת בדיקה', 'מסעדה לרבות הגשת משקאות משכרים'),
    'professionals.fast_food' => $row(3, '402207', 'פיצה בדיקה', 'הכנת פיצות'),
    'food_catering.cafes' => $row(4, '402203', 'בית בדיקה', 'בית קפה'),
    'professionals.catering' => $row(5, '406500', 'מטבח בדיקה', 'הסעדה קיטרינג'),
    'professionals.grocery_food' => $row(6, '407202', 'מכולת בדיקה'),
    'food_catering.meat_deli' => $row(7, '407205', 'מעדניית בדיקה'),
    'food_catering.bars' => $row(8, '408001', 'פאב בדיקה'),
    'professionals.venues' => $row(9, '709000', 'אולם בדיקה'),
    'travel_leisure.hotels_guesthouses' => $row(10, '701101', 'מלון בדיקה'),
];
$makeSource = static fn (TelAvivFixtureHttp $http, array $overrides = []): TelAvivBusinessLicenseSource => new TelAvivBusinessLicenseSource(
    $overrides + ['min_interval_seconds' => 0, 'page_size' => 3], $http, $repository, 'SveeveeTest/1.0',
);
$tests = [];
$tests['all ten verified license categories map and pagination is reused'] = static function () use ($fixtures, $makeSource, $assert): void {
    $http = new TelAvivFixtureHttp(array_values($fixtures));
    $source = $makeSource($http);
    foreach ($fixtures as $category => $fixture) {
        $rows = iterator_to_array($source->research(new ResearchTarget('Tel Aviv', $category), 100));
        $assert(count($rows) === 1 && $rows[0]['name'] === $fixture['t_shem_esek'], 'Wrong classification for '.$category);
        $assert($rows[0]['address'] === ['street' => 'דיזנגוף', 'city' => 'Tel Aviv'], 'Must preserve documented address without fabricating a house number.');
        $assert(! isset($rows[0]['phone']), 'Must not fabricate a phone.');
    }
    $assert(count($http->requests) === 5, 'Expected one count request plus four pages across all ten categories.');
};
$tests['unsupported city or neighborhood makes no request'] = static function () use ($makeSource, $assert): void {
    $http = new TelAvivFixtureHttp([]);
    $source = $makeSource($http);
    $assert(iterator_to_array($source->research(new ResearchTarget('Beersheba', 'food_catering.restaurants'), 100)) === [], 'Wrong city accepted.');
    $assert(iterator_to_array($source->research(new ResearchTarget('Tel Aviv', 'food_catering.restaurants', 'Florentin'), 100)) === [], 'Unverified neighborhood accepted.');
    $assert($http->requests === [], 'Unsupported target caused an HTTP request.');
};
$tests['invalid, expired, unnamed and non-business licenses are excluded'] = static function () use ($row, $makeSource, $assert): void {
    $http = new TelAvivFixtureHttp([
        array_replace($row(11, '402100'), ['taarich_tokef' => '2020-01-01']),
        array_replace($row(12, '402100'), ['taarich_tokef' => '2999-02-30']),
        $row(13, '402100', ''),
        array_replace($row(14, '402100'), ['shem_rechov' => '']),
        $row(15, '708101', 'קייטנת ילדים'),
        $row(16, '406300', 'מחסן', 'אחסנת מזון למסעדה'),
    ]);
    $source = $makeSource($http);
    foreach (['food_catering.restaurants', 'professionals.venues'] as $category) {
        $assert(iterator_to_array($source->research(new ResearchTarget('Tel Aviv', $category), 100)) === [], 'Invalid record was accepted.');
    }
};
$tests['stable provenance survives row renumbering and unchanged records skip before limit'] = static function () use ($row, $makeSource, $assert, $database): void {
    $target = new ResearchTarget('Tel Aviv', 'food_catering.restaurants');
    $original = $row(20, '402100', 'מסעדה ראשונה');
    $first = iterator_to_array($makeSource(new TelAvivFixtureHttp([$original]))->research($target, 1))[0];
    $database->pdo->prepare('INSERT INTO researched_urls (adapter, url_hash, source_url, status, checked_at, raw_hash) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['tel_aviv_business_licenses', hash('sha256', $first['source_url']), $first['source_url'], 'success', Clock::now(), SourceFingerprint::hash($first)]);
    $renumbered = array_replace($original, ['oid_rishayon' => 990]);
    $second = $row(21, '402100', 'מסעדה שנייה');
    $rows = iterator_to_array($makeSource(new TelAvivFixtureHttp([$renumbered, $second]))->research($target, 1));
    $assert(count($rows) === 1 && $rows[0]['name'] === 'מסעדה שנייה', 'Cached record consumed the limit or row renumbering changed provenance.');
    $changed = array_replace($renumbered, ['shem_rechov' => 'אלנבי']);
    $rows = iterator_to_array($makeSource(new TelAvivFixtureHttp([$changed]))->research($target, 1));
    $assert(count($rows) === 1 && $rows[0]['address']['street'] === 'אלנבי', 'Changed records must be eligible before refresh age.');
};
$tests['ArcGIS errors and broken pagination cannot silently appear as zero'] = static function () use ($row, $makeSource, $assert): void {
    foreach (['apiError', 'emptyPage', 'repeatPage'] as $mode) {
        $http = new TelAvivFixtureHttp([$row(30, '402100'), $row(31, '402100')]);
        $http->$mode = true;
        $failed = false;
        try {
            iterator_to_array($makeSource($http, ['page_size' => 1])->research(new ResearchTarget('Tel Aviv', 'food_catering.restaurants'), 100));
        } catch (RuntimeException) {
            $failed = true;
        }
        $assert($failed, $mode.' was treated as successful research.');
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
unset($database, $repository, $makeSource, $tests, $test);
gc_collect_cycles();
foreach ([$databasePath.'-wal', $databasePath.'-shm', $databasePath] as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}
fwrite(STDOUT, '5 tests, '.$failures." failures\n");
exit($failures === 0 ? 0 : 1);
