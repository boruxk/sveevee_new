<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\SourceFingerprint;

$directory = sys_get_temp_dir().'/sveevee-overture-'.bin2hex(random_bytes(8));
if (! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create temporary Overture test directory.');
}
$path = $directory.'/places.sqlite';
$fixture = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fixture->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
$fixture->exec("INSERT INTO metadata VALUES ('schema_version','1'), ('status','complete'), ('country','IL')");
$fixture->exec(<<<'SQL'
CREATE TABLE places (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, category_key TEXT NOT NULL, city TEXT NOT NULL,
    street TEXT, phone TEXT, email TEXT, website TEXT, social_links TEXT NOT NULL,
    confidence REAL NOT NULL, release TEXT NOT NULL, source_url TEXT NOT NULL,
    source_name TEXT NOT NULL, source_checked_at TEXT NOT NULL, source_metadata TEXT NOT NULL
)
SQL);
$fixture->exec('CREATE INDEX places_target_idx ON places(city, category_key, id)');
$database = new Database($directory.'/worker.sqlite');
$normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv', 'Jerusalem']);
$repository = new WorkerRepository($database, $normalizer, new BusinessMerger);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expectFailure = static function (callable $operation, string $contains) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), $contains), 'Unexpected failure: '.$error->getMessage());

        return;
    }
    throw new RuntimeException('Expected a source error containing '.$contains);
};
$row = static fn (string $id, array $overrides = []): array => array_replace([
    'id' => $id,
    'name' => 'מסעדת בדיקה '.$id,
    'category_key' => 'food_catering.restaurants',
    'city' => 'Tel Aviv',
    'street' => 'דיזנגוף 10',
    'phone' => '+97235550100',
    'email' => 'hello@example.org',
    'website' => 'https://example.org/',
    'social_links' => '{"instagram":"https://instagram.com/example"}',
    'confidence' => 0.9,
    'release' => '2026-08-19.0',
    'source_url' => 'https://docs.overturemaps.org/gers/#'.rawurlencode($id),
    'source_name' => 'Overture Maps Places',
    'source_checked_at' => '2026-09-09T10:00:00+00:00',
    'source_metadata' => '{"sources":[{"dataset":"Meta","record_id":"123","license":"CDLA-Permissive-2.0"}]}',
], $overrides);
$loadRows = static function (array $rows) use ($fixture): void {
    $fixture->exec('DELETE FROM places');
    $fixture->beginTransaction();
    $statement = $fixture->prepare('INSERT INTO places VALUES ('.implode(',', array_fill(0, 15, '?')).')');
    foreach ($rows as $record) {
        $statement->execute(array_values($record));
    }
    $fixture->commit();
};
$makeSource = static fn (array $config = []): OverturePlacesSource => new OverturePlacesSource(
    $config + ['database_path' => 'places.sqlite'], $directory, $repository,
);
$target = new ResearchTarget('Tel Aviv', 'food_catering.restaurants');
$remember = static function (array $business, ?string $checkedAt = null) use ($database): void {
    $database->pdo->prepare('INSERT OR REPLACE INTO researched_urls (adapter, url_hash, source_url, status, checked_at, raw_hash) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['overture_places', hash('sha256', $business['source_url']), $business['source_url'], 'success', $checkedAt ?? Clock::now(), SourceFingerprint::hash($business)]);
};

$tests = [];
$tests['all ten categories select their exact city and preserve useful fields and provenance'] = static function () use ($row, $loadRows, $makeSource, $normalizer, $assert): void {
    $categories = [
        'food_catering.bakery', 'food_catering.restaurants', 'professionals.fast_food', 'food_catering.cafes',
        'professionals.catering', 'professionals.grocery_food', 'food_catering.meat_deli', 'food_catering.bars',
        'professionals.venues', 'travel_leisure.hotels_guesthouses',
    ];
    $records = [];
    foreach ($categories as $index => $category) {
        $records[] = $row('tel-aviv-'.$index, ['category_key' => $category]);
        $records[] = $row('jerusalem-'.$index, ['category_key' => $category, 'city' => 'Jerusalem']);
    }
    $loadRows($records);
    $source = $makeSource();
    foreach ($categories as $index => $category) {
        $target = new ResearchTarget('Tel Aviv', $category);
        $businesses = iterator_to_array($source->research($target, 100));
        $assert(count($businesses) === 1 && $businesses[0]['name'] === 'מסעדת בדיקה tel-aviv-'.$index, 'Wrong city or category selected.');
        $candidate = $normalizer->normalize($businesses[0], $target, $source->name());
        $assert($candidate->data['address'] === ['city' => 'Tel Aviv', 'street' => 'דיזנגוף 10'], 'Address was changed.');
        $assert($candidate->data['phone'] === '+97235550100' && $candidate->data['contact_email'] === 'hello@example.org', 'Contact fields were lost.');
        $assert($candidate->data['website'] === 'https://example.org/' && $candidate->data['socials']['instagram'] === 'https://instagram.com/example', 'Links were lost.');
        $assert($candidate->sources[0]->raw['source_metadata']['sources'][0]['license'] === 'CDLA-Permissive-2.0', 'Original provenance was lost.');
        $assert(! isset($candidate->data['id']) && ! isset($candidate->data['source_metadata']), 'External ID or audit fields leaked into app payload.');
    }
    $assert(iterator_to_array($source->research(new ResearchTarget("Tel Aviv' OR 1=1 --", 'food_catering.restaurants'), 100)) === [], 'City selection is not exact.');
};
$tests['low-confidence and unnamed rows are excluded without invented information'] = static function () use ($row, $loadRows, $makeSource, $target, $assert): void {
    $loadRows([
        $row('quality-a', ['confidence' => 0.749]),
        $row('quality-b', ['name' => ' ']),
        $row('quality-c', ['confidence' => 1.1]),
        $row('quality-d', ['confidence' => 0.75, 'phone' => null, 'email' => null, 'website' => null, 'street' => null, 'social_links' => '{}']),
    ]);
    $businesses = iterator_to_array($makeSource()->research($target, 100));
    $assert(count($businesses) === 1 && $businesses[0]['source_metadata']['overture_id'] === 'quality-d', 'Quality threshold was not respected.');
    foreach (['phone', 'contact_email', 'website', 'socials', 'public_description', 'opening_hours'] as $field) {
        $assert(! isset($businesses[0][$field]), 'An unavailable '.$field.' was fabricated.');
    }
    $assert($businesses[0]['address'] === ['city' => 'Tel Aviv'], 'An unavailable street was fabricated.');
};
$tests['unsupported targets and zero limits never open a missing extract'] = static function () use ($makeSource, $target, $assert): void {
    $source = $makeSource(['database_path' => 'missing.sqlite']);
    $assert(iterator_to_array($source->research(new ResearchTarget('Tel Aviv', 'unsupported'), 100)) === [], 'Unsupported category accepted.');
    $assert(iterator_to_array($source->research(new ResearchTarget('Tel Aviv', 'food_catering.restaurants', 'Florentin'), 100)) === [], 'Unverified neighborhood accepted.');
    $assert(iterator_to_array($source->research($target, 0)) === [], 'Zero limit emitted a record.');
};
$tests['unchanged rows and release-only changes do not consume the limit but changed contacts do'] = static function () use ($row, $loadRows, $makeSource, $target, $remember, $assert): void {
    $loadRows([$row('refresh-a')]);
    $first = iterator_to_array($makeSource()->research($target, 1))[0];
    $remember($first);
    $loadRows([
        $row('refresh-a', ['release' => '2026-09-23.0', 'source_checked_at' => '2026-09-24T10:00:00+00:00', 'confidence' => 0.95]),
        $row('refresh-b'),
    ]);
    $businesses = iterator_to_array($makeSource()->research($target, 1));
    $assert(count($businesses) === 1 && $businesses[0]['source_metadata']['overture_id'] === 'refresh-b', 'A cached row or release-only change consumed the limit.');
    $loadRows([$row('refresh-a', ['website' => 'https://changed.example.org/'])]);
    $businesses = iterator_to_array($makeSource()->research($target, 1));
    $assert(count($businesses) === 1 && $businesses[0]['website'] === 'https://changed.example.org/', 'Changed contact was incorrectly cached.');
    $loadRows([$row('refresh-a')]);
    $remember($first, gmdate(DATE_ATOM, time() - 31 * 86400));
    $assert(count(iterator_to_array($makeSource()->research($target, 1))) === 1, 'Unchanged row was not eligible after its refresh period.');
};
$tests['streaming stops at the caller limit and supports more than one thousand source rows'] = static function () use ($row, $loadRows, $makeSource, $target, $expectFailure, $assert): void {
    $loadRows([$row('stream-a'), $row('stream-b', ['source_metadata' => '{broken'])]);
    $assert(count(iterator_to_array($makeSource()->research($target, 1))) === 1, 'Source eagerly mapped records beyond its limit.');
    $expectFailure(static fn () => iterator_to_array($makeSource()->research($target, 100)), 'invalid source_metadata JSON');
    $records = [];
    for ($index = 0; $index < 1200; $index++) {
        $records[] = $row('many-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT));
    }
    $loadRows($records);
    $assert(count(iterator_to_array($makeSource()->research($target, PHP_INT_MAX))) === 1200, 'Source dataset was silently capped at the run write limit.');
    $assert(count(iterator_to_array($makeSource()->research($target, 100))) === 100, 'Explicit source limit was ignored.');
};
$tests['missing, unfinished, wrong-country, unknown-schema and corrupt extracts fail clearly'] = static function () use ($makeSource, $target, $fixture, $directory, $expectFailure, $assert): void {
    $expectFailure(static fn () => iterator_to_array($makeSource(['database_path' => 'missing.sqlite'])->research($target, 1)), 'not found or unreadable');
    $assert(! is_file($directory.'/missing.sqlite'), 'Read-only discovery created an empty database.');
    foreach ([['status', 'building', 'complete Israel'], ['country', 'PS', 'complete Israel'], ['schema_version', '2', 'Unsupported']] as [$key, $value, $error]) {
        $original = $fixture->query("SELECT value FROM metadata WHERE key = '{$key}'")->fetchColumn();
        $fixture->prepare('UPDATE metadata SET value = ? WHERE key = ?')->execute([$value, $key]);
        try {
            $expectFailure(static fn () => iterator_to_array($makeSource()->research($target, 1)), $error);
        } finally {
            $fixture->prepare('UPDATE metadata SET value = ? WHERE key = ?')->execute([$original, $key]);
        }
    }
    file_put_contents($directory.'/corrupt.sqlite', 'not a database');
    $expectFailure(static fn () => iterator_to_array($makeSource(['database_path' => 'corrupt.sqlite'])->research($target, 1)), 'Unable to read');
};
$tests['invalid source URLs and metadata cannot silently appear as successful empty results'] = static function () use ($row, $loadRows, $makeSource, $target, $expectFailure): void {
    foreach ([
        ['source_url', 'javascript:alert(1)', 'invalid source URL'],
        ['source_metadata', '[]', 'expected a JSON object'],
        ['social_links', '{bad', 'invalid social_links JSON'],
    ] as [$field, $value, $message]) {
        $loadRows([$row('broken-row', [$field => $value])]);
        $expectFailure(static fn () => iterator_to_array($makeSource()->research($target, 1)), $message);
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
$count = count($tests);
unset($fixture, $database, $repository, $normalizer, $loadRows, $makeSource, $remember, $tests, $test);
gc_collect_cycles();
foreach (['places.sqlite', 'worker.sqlite-wal', 'worker.sqlite-shm', 'worker.sqlite', 'corrupt.sqlite'] as $filename) {
    if (is_file($directory.'/'.$filename)) {
        @unlink($directory.'/'.$filename);
    }
}
@rmdir($directory);
fwrite(STDOUT, "{$count} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
