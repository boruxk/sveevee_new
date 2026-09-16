<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Research\OpenStreetMap\DatasetPreparer;
use Sveevee\Worker\Research\OpenStreetMap\PlaceMapper;
use Sveevee\Worker\Research\OpenStreetMap\PlacesSource;
use Sveevee\Worker\Research\OpenStreetMap\WeeklyOpeningHours;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;

$directory = sys_get_temp_dir().'/sveevee-osm-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) { throw new RuntimeException($message); }
};
$throws = static function (callable $callback) use ($assert): void {
    try { $callback(); } catch (Throwable) { $assert(true, 'Expected rejection.'); return; }
    $assert(false, 'Expected invalid input to be rejected.');
};
$raw = static fn (string $id, array $tags = [], string $status = 'active'): array => [
    'id' => $id, 'country' => 'IL', 'country_filter' => 'osm_admin_boundary_IL',
    'tags' => $tags + ['name' => 'Branch', 'shop' => 'future_category', 'addr:city' => 'Unmapped city'],
    'version' => 3, 'timestamp' => '2026-09-15T00:00:00Z', 'latitude' => 32.1, 'longitude' => 34.8,
    'geometry_method' => str_starts_with($id, 'node/') ? 'node' : 'area_interior', 'lifecycle_status' => $status,
];
$export = static function (array $rows, string $snapshot = 'a') use ($directory): array {
    $jsonl = $directory.'/export.jsonl';
    file_put_contents($jsonl, implode('', array_map(static fn ($row) => Json::encode($row)."\n", $rows)));
    $manifest = $directory.'/manifest.json';
    file_put_contents($manifest, Json::encode(['provider' => 'osm_places', 'status' => 'complete', 'country' => 'IL',
        'country_filter' => 'osm_admin_boundary_IL', 'snapshot_id' => str_repeat($snapshot, 64), 'release' => '2026-09-15',
        'row_count' => count($rows), 'jsonl_sha256' => hash_file('sha256', $jsonl)]));
    return [$jsonl, $manifest];
};
$source = $reader = $db = $database = $repository = $iterator = null;
try {
    $hours = array_column(WeeklyOpeningHours::parse('Mo-Fr 08:00-17:00; Sa 09:00-12:00; Su off'), null, 'weekday');
    $assert(count($hours) === 7 && $hours['monday']['opens_at'] === '08:00' && ! $hours['sunday']['is_open'], 'Exact weekly hours map to the API weekday names.');
    $assert(count(WeeklyOpeningHours::parse('08:00-18:00')) === 7, 'An omitted weekday applies every day.');
    $assert(WeeklyOpeningHours::parse('Su-Tu 08:00-17:00')[1]['is_open'], 'Wrapping weekday ranges remain exact.');
    foreach (['24/7', 'Mo-Fr 08:00-17:00; PH off', 'Mo 08:00-12:00,13:00-18:00', 'Mo 22:00-02:00',
        'Mo 00:00-24:00', 'Mo 08:00-17:00; Mo off', 'sunrise-sunset', 'Mo-Fr 08:00-17:00; nonsense'] as $expression) {
        $assert(WeeklyOpeningHours::parse($expression) === [], 'Unsupported hours must stay raw-only: '.$expression);
    }
    $mapper = new PlaceMapper;
    $mapped = $mapper->map($raw('node/123', ['opening_hours' => 'Mo-Fr 08:00-17:00; PH off']), '2026-09-15', '2026-09-16T00:00:00Z');
    $assert($mapped['category_key'] === null && $mapped['address']['city'] === 'Unmapped city', 'Unknown catalog values must not block a business.');
    $assert($mapped['source_metadata']['source_categories'][0]['key'] === 'shop=future_category', 'Original category identity retained.');
    $assert($mapped['opening_hours'] === [] && $mapped['source_metadata']['opening_hours_raw'] === 'Mo-Fr 08:00-17:00; PH off', 'Holiday exceptions must not be lost through partial parsing.');
    $assert($mapped['source_metadata']['osm_id'] === '123' && $mapped['source_url'] === 'https://www.openstreetmap.org/node/123', 'OSM entity type is part of source identity.');
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, []);
    $normalized = $normalizer->normalize($mapped, ResearchTarget::sourceAll('osm_places'), 'osm_places')->data;
    $assert(! isset($normalized['opening_hours']) && $normalized['source']['metadata']['opening_hours_raw'] !== null, 'Shared normalizer must not parse complex raw OSM syntax.');
    $mapped = $mapper->map($raw('way/123', ['shop' => 'bakery', 'opening_hours' => 'Mo-Fr 08:00-17:00']), '2026-09-15', '2026-09-16T00:00:00Z');
    $normalized = $normalizer->normalize($mapped, ResearchTarget::sourceAll('osm_places'), 'osm_places')->data;
    $assert($mapped['category_key'] === 'food_catering.bakery' && count($normalized['opening_hours']) === 7, 'Supported hours and explicit category mapping reach the import payload.');
    $preparer = new DatasetPreparer($mapper);
    [$jsonl, $manifest] = $export([$raw('node/1'), $raw('node/2', [], 'disused'), $raw('node/3', ['name' => '?']), $raw('relation/1'), $raw('way/1')]);
    $destination = $directory.'/places.sqlite';
    $result = $preparer->importJsonl($jsonl, $manifest, $destination);
    $assert($result['counts']['accepted'] === 5 && $result['counts']['closed'] === 1 && $result['counts']['invalid'] === 1, 'Complete snapshots retain inactive and unusable records for accounting.');
    $baseline = hash_file('sha256', $destination);
    foreach ([[$raw('node/1'), $raw('node/1')], [array_replace($raw('node/1'), ['country' => 'PS'])]] as $rows) {
        [$jsonl, $manifest] = $export($rows, 'b');
        $throws(static fn () => $preparer->importJsonl($jsonl, $manifest, $destination));
        $assert(hash_file('sha256', $destination) === $baseline, 'Rejected preparation preserves the prior database byte-for-byte.');
    }
    [$jsonl, $manifest] = $export([$raw('node/1')], 'b');
    file_put_contents($jsonl, 'truncated');
    $throws(static fn () => $preparer->importJsonl($jsonl, $manifest, $destination));
    $database = new Database(':memory:');
    $repository = new WorkerRepository($database, $normalizer, new BusinessMerger, ['osm_places']);
    $source = new PlacesSource(['database_path' => $destination], dirname(__DIR__), $repository);
    $iterator = $source->research(ResearchTarget::sourceAll('osm_places'), 9000);
    $iterator->rewind();
    $first = $iterator->current();
    $assert($first['source_metadata']['source_id'] === 'node/1', 'Start at the first stable OSM identity.');
    $iterator = null;
    $reader = new PlacesSource(['database_path' => $destination], dirname(__DIR__), $repository);
    $iterator = $reader->research(ResearchTarget::sourceAll('osm_places'), 9000);
    $iterator->rewind();
    $retry = $iterator->current();
    $assert($retry['source_metadata']['source_id'] === 'node/1', 'Unacknowledged rows repeat after interruption; reader never runs ahead.');
    $throws(static fn () => $iterator->next());
    $reader->acknowledge($retry);
    $iterator = null;
    $seen = [];
    foreach ($reader->research(ResearchTarget::sourceAll('osm_places'), 9000) as $business) {
        $seen[] = $business['source_metadata']['source_id'];
        $reader->acknowledge($business);
    }
    $assert($seen === ['relation/1', 'way/1'], 'Inactive/invalid rows skip publication; same numeric ID across entity types remains distinct.');
    $progress = $reader->progress();
    $assert($progress['scanned'] === 5 && $progress['remaining'] === 0 && $progress['closed'] === 1 && $progress['invalid'] === 1, 'Progress accounts for every source row without infinite repeats.');
    $assert(iterator_to_array($reader->research(ResearchTarget::sourceAll('osm_places'), 9000)) === [], 'Exhausted snapshot stays exhausted.');
    echo 'OSM: '.$assertions." assertions passed.\n";
} finally {
    $source = $reader = $db = $database = $repository = $iterator = null;
    gc_collect_cycles();
    foreach (glob($directory.'/*') as $path) { if (is_file($path)) { unlink($path); } }
    rmdir($directory);
}
