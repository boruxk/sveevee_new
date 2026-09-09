<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Research\Overture\DatasetPreparer;
use Sveevee\Worker\Research\Overture\PlaceMapper;
use Sveevee\Worker\Research\Overture\ReleaseCatalog;
use Sveevee\Worker\Support\Json;

final class OvertureCatalogFixture implements HttpClientInterface
{
    public function __construct(public array $responses) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        return isset($this->responses[$url]) ? new HttpResponse(200, [], Json::encode($this->responses[$url])) : new HttpResponse(404, [], '{}');
    }
}

$directory = sys_get_temp_dir().'/sveevee-overture-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $action, string $message) use ($assert): void {
    try {
        $action();
    } catch (Throwable) {
        return;
    }
    $assert(false, $message);
};
$mapper = new PlaceMapper(['Haifa', 'Tel Aviv'], ['Haifa' => ['חיפה'], 'Tel Aviv' => ['תל אביב - יפו']], 0.75);
$row = static fn (string $id = '08f347c000000001') => [
    'id' => $id, 'names' => ['primary' => 'מאפיית בדיקה בע״מ'],
    'addresses' => [['country' => 'IL', 'locality' => 'חיפה', 'freeform' => 'הרצל 10']],
    'phones' => ['invalid', '04-1234567'], 'emails' => ['broken', 'Hello@Example.org'],
    'websites' => ['javascript:alert(1)', 'example.org'], 'socials' => ['https://www.instagram.com/test', 'https://facebook.com.evil.test/page'],
    'taxonomy' => ['primary' => 'bakery', 'hierarchy' => ['food_and_drink', 'bakery']],
    'confidence' => 0.9, 'operating_status' => null,
    'sources' => [['dataset' => 'meta', 'record_id' => 'source-1', 'property' => '']],
    'bbox' => ['xmin' => 35.0, 'ymin' => 32.0, 'xmax' => 35.0, 'ymax' => 32.0],
];
$write = static function (string $name, array $rows) use ($directory): string {
    $path = $directory.'/'.$name.'.jsonl';
    file_put_contents($path, implode('', array_map(static fn (array $value): string => Json::encode($value)."\n", $rows)));

    return $path;
};
$tests = [];
$tests['contacts, original provenance and exact Israel address are retained'] = static function () use ($mapper, $row, $assert): void {
    $raw = $row();
    array_unshift($raw['addresses'], ['country' => 'US', 'locality' => 'Haifa', 'freeform' => 'Wrong address']);
    $mapped = $mapper->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z');
    $assert($mapped['city'] === 'Haifa' && $mapped['street'] === 'הרצל 10', 'Only the IL address may be selected.');
    $assert($mapped['name'] === $raw['names']['primary'], 'Raw name must stay stable for existing identity matching.');
    $assert($mapped['phone'] === '+97241234567' && $mapped['email'] === 'hello@example.org' && $mapped['website'] === 'https://example.org', 'Choose the first valid contact in each list.');
    $assert((array) $mapped['social_links'] === ['instagram' => 'https://www.instagram.com/test'], 'Social network domain must match exactly.');
    $assert($mapped['source_metadata']['sources'] === $raw['sources'], 'Upstream provenance must survive preparation.');
    $assert(str_ends_with($mapped['source_url'], '?feature=places.place.'.$raw['id']), 'Stable Explorer feature link is required.');
};
$tests['country, confidence, closure, name and canonical city filters'] = static function () use ($mapper, $row, $assert): void {
    foreach (['country', 'city', 'street', 'confidence', 'nonfinite', 'closed', 'name', 'category'] as $scenario) {
        $raw = $row();
        match ($scenario) {
            'country' => $raw['addresses'][0]['country'] = 'PS',
            'city' => $raw['addresses'][0]['locality'] = 'Haiffa',
            'street' => $raw['addresses'][0]['freeform'] = '',
            'confidence' => $raw['confidence'] = 0.74,
            'nonfinite' => $raw['confidence'] = NAN,
            'closed' => $raw['operating_status'] = 'permanently_closed',
            'name' => $raw['names']['primary'] = 'בע״מ',
            'category' => $raw['taxonomy'] = ['primary' => 'accountant', 'hierarchy' => []],
        };
        $assert($mapper->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z', $reason) === null && $reason !== null, 'Record should be skipped: '.$scenario);
    }
    $raw = $row();
    $raw['confidence'] = 0.75;
    $assert($mapper->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z') !== null, 'Threshold is inclusive; unknown operating status may be retained.');
};
$tests['all ten categories and narrower deli take precedence'] = static function () use ($mapper, $row, $assert): void {
    $categories = [
        'bakery' => 'food_catering.bakery', 'italian_restaurant' => 'food_catering.restaurants',
        'pizza_restaurant' => 'professionals.fast_food', 'coffee_shop' => 'food_catering.cafes',
        'caterer' => 'professionals.catering', 'supermarket' => 'professionals.grocery_food',
        'delicatessen' => 'food_catering.meat_deli', 'gastropub' => 'food_catering.bars',
        'event_venue' => 'professionals.venues', 'hostel' => 'travel_leisure.hotels_guesthouses',
    ];
    foreach ($categories as $primary => $expected) {
        $raw = $row();
        $raw['taxonomy'] = ['primary' => $primary, 'hierarchy' => match ($primary) {
            'delicatessen' => ['grocery_store'], 'italian_restaurant', 'pizza_restaurant', 'gastropub' => ['restaurant'], default => [],
        }];
        $assert($mapper->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z')['category_key'] === $expected, 'Wrong category: '.$primary);
    }
    $raw = $row();
    $raw['taxonomy'] = ['primary' => 'bed_and_breakfast', 'hierarchy' => ['private_lodging', 'hotel']];
    $assert($mapper->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z') === null, 'Private lodging must not enter business categories.');
};
$tests['ambiguous aliases fail and explicit source spellings map'] = static function () use ($throws, $row, $assert): void {
    $throws(static fn () => new PlaceMapper(['Haifa', 'Tel Aviv'], ['Haifa' => ['same'], 'Tel Aviv' => ['same']]), 'Ambiguous city alias was accepted.');
    $mapping = PlaceMapper::fromConfig(['cities' => ['Kiryat Ono'], 'sources' => ['overture_places' => ['min_confidence' => 0.75]]]);
    $raw = $row();
    $raw['addresses'][0]['locality'] = 'קרית אונו';
    $assert($mapping->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z')['city'] === 'Kiryat Ono', 'Observed alternate Hebrew spelling must map explicitly.');
};
$tests['complete SQLite snapshot publishes with metadata and compound index'] = static function () use ($mapper, $row, $write, $directory, $assert): void {
    $invalid = $row('invalid-country');
    $invalid['addresses'][0]['country'] = 'PS';
    $output = $directory.'/valid.sqlite';
    $result = (new DatasetPreparer($mapper))->importJsonl($write('valid', [$row(), $invalid]), '2026-08-19.0', $output, 2);
    $assert($result['counts']['accepted'] === 1 && $result['counts']['skipped']['country'] === 1, 'Unexpected accepted/skipped count.');
    $db = new PDO('sqlite:'.$output);
    $metadata = $db->query('SELECT key, value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($metadata['schema_version'] === '1' && $metadata['status'] === 'complete' && $metadata['country'] === 'IL' && $metadata['row_count'] === '1', 'Snapshot metadata is incomplete.');
    $assert($db->query('PRAGMA quick_check')->fetchColumn() === 'ok', 'SQLite snapshot is corrupt.');
    $assert(count($db->query("PRAGMA index_info('places_city_category_id')")->fetchAll()) === 3, 'Expected city/category/id index.');
    $db = null;
};
$tests['truncated, duplicate, empty and mismatched exports preserve an existing snapshot'] = static function () use ($mapper, $row, $write, $directory, $throws, $assert): void {
    $preparer = new DatasetPreparer($mapper);
    $output = $directory.'/atomic.sqlite';
    $preparer->importJsonl($write('baseline', [$row()]), '2026-08-19.0', $output, 1);
    $hash = hash_file('sha256', $output);
    $empty = $row();
    $empty['addresses'][0]['locality'] = 'Unknown';
    $schema = $row();
    unset($schema['sources']);
    $cases = [
        [$write('duplicate', [$row(), $row()]), 2],
        [$write('empty', [$empty]), 1],
        [$write('wrong-count', [$row()]), 2],
        [$write('schema', [$schema]), 1],
    ];
    $truncated = $write('truncated', [$row()]);
    file_put_contents($truncated, rtrim((string) file_get_contents($truncated), "\n"));
    $cases[] = [$truncated, 1];
    foreach ($cases as [$path, $count]) {
        $throws(static fn () => $preparer->importJsonl($path, '2026-08-19.0', $output, $count), 'Broken export was published.');
        $assert(hash_file('sha256', $output) === $hash, 'Good snapshot changed after a failed preparation.');
    }
    $assert(glob($output.'.stage-*') === [], 'Failed preparation must remove staging files.');
};
$tests['older and suspiciously reduced snapshots preserve current data'] = static function () use ($mapper, $row, $write, $directory, $throws, $assert): void {
    $preparer = new DatasetPreparer($mapper);
    $output = $directory.'/replacement.sqlite';
    $preparer->importJsonl($write('three', [$row('one'), $row('two'), $row('three')]), '2026-08-19.0', $output, 3);
    $hash = hash_file('sha256', $output);
    $one = $write('one', [$row()]);
    $throws(static fn () => $preparer->importJsonl($one, '2026-08-19.0', $output, 1), 'Large unexplained loss was published.');
    $throws(static fn () => $preparer->importJsonl($one, '2026-07-22.0', $output, 1), 'Older release was published.');
    $assert(hash_file('sha256', $output) === $hash, 'Replacement guard changed the existing file.');
};
$tests['STAC latest resolves real root child links and partition coverage is complete'] = static function () use ($assert, $throws): void {
    $base = 'https://stac.overturemaps.org/2026-08-19.0/places/place/';
    $aws = 'https://overturemaps-us-west-2.s3.us-west-2.amazonaws.com/release/2026-08-19.0/theme=places/type=place/part-israel.parquet';
    $http = new OvertureCatalogFixture([
        'https://stac.overturemaps.org/catalog.json' => ['links' => [
            ['rel' => 'self', 'href' => 'https://stac.overturemaps.org/catalog.json'],
            ['rel' => 'child', 'href' => 'https://stac.overturemaps.org/2026-08-19.0/catalog.json', 'title' => 'Latest Overture Release'],
            ['rel' => 'child', 'href' => 'https://stac.overturemaps.org/2026-07-22.0/catalog.json'],
        ]],
        $base.'collection.json' => ['type' => 'Collection', 'id' => 'place', 'links' => [
            ['rel' => 'item', 'href' => $base.'00008/00008.json'], ['rel' => 'item', 'href' => $base.'00009/00009.json'],
        ]],
        $base.'00008/00008.json' => ['type' => 'Feature', 'bbox' => [3, -84, 72, 40], 'assets' => ['aws' => ['href' => $aws]]],
        $base.'00009/00009.json' => ['type' => 'Feature', 'bbox' => [-90, 40, -80, 50]],
    ]);
    $catalog = new ReleaseCatalog($http);
    $assert($catalog->latest() === '2026-08-19.0', 'Latest root uses child links.');
    $assert($catalog->files('2026-08-19.0') === [$aws], 'Select exactly the intersecting official partition.');
    unset($http->responses[$base.'00009/00009.json']);
    $throws(static fn () => $catalog->files('2026-08-19.0'), 'A missing partition metadata response cannot silently yield a partial catalog.');
    $throws(static fn () => ReleaseCatalog::validateRelease('2026-02-31.0'), 'Invalid release date accepted.');
};
$tests['real DuckDB Parquet export filters Israel and publishes only complete data'] = static function () use ($mapper, $row, $write, $directory, $assert, $throws): void {
    $duckdb = getenv('SVEEVEE_TEST_DUCKDB') ?: dirname(__DIR__).'/var/research-overture/tools/duckdb.exe';
    if (! is_file($duckdb)) {
        fwrite(STDOUT, "SKIP DuckDB fixture: set SVEEVEE_TEST_DUCKDB to run the optional local Parquet round trip.\n");

        return;
    }
    $foreign = $row('foreign');
    $foreign['addresses'][0]['country'] = 'PS';
    $low = $row('low');
    $low['confidence'] = 0.1;
    $outside = $row('outside');
    $outside['bbox']['xmin'] = 1.0;
    $input = $write('parquet-input', [$row(), $foreign, $low, $outside]);
    $parquet = $directory.'/fixture.parquet';
    $quote = static fn (string $path): string => "'".str_replace("'", "''", str_replace('\\', '/', $path))."'";
    $sql = $directory.'/fixture.sql';
    file_put_contents($sql, 'COPY (SELECT * FROM read_json_auto('.$quote($input).')) TO '.$quote($parquet).' (FORMAT PARQUET);');
    $init = $directory.'/empty.init';
    file_put_contents($init, '');
    $process = proc_open([$duckdb, '-batch', '-bail', '-init', $init, ':memory:'], [0 => ['file', $sql, 'r'], 1 => ['file', $directory.'/duckdb.stdout', 'w'], 2 => ['file', $directory.'/duckdb.stderr', 'w']], $pipes);
    $assert(is_resource($process) && proc_close($process) === 0, 'Could not create real Parquet fixture.');
    $result = (new DatasetPreparer($mapper))->prepare($duckdb, [$parquet], '2026-08-19.0', $directory.'/parquet.sqlite');
    $assert($result['counts']['read'] === 1 && $result['counts']['accepted'] === 1, 'DuckDB must filter country, bounds and confidence before PHP mapping.');
    $hash = hash_file('sha256', $directory.'/parquet.sqlite');
    $throws(static fn () => (new DatasetPreparer($mapper))->prepare($duckdb, [$input], '2026-08-19.0', $directory.'/parquet.sqlite'), 'Invalid Parquet should fail in DuckDB.');
    $assert(hash_file('sha256', $directory.'/parquet.sqlite') === $hash, 'A failed DuckDB subprocess must preserve the existing snapshot.');
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
    foreach (glob($directory.'/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
fwrite(STDOUT, count($tests).' tests, '.$failed.' failures.'.PHP_EOL);
exit($failed > 0 ? 1 : 0);
