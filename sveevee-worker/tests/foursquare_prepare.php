<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Research\Foursquare\DatasetPreparer;
use Sveevee\Worker\Research\Foursquare\PlaceMapper;
use Sveevee\Worker\Support\Json;

$directory = sys_get_temp_dir().'/sveevee-fsq-prepare-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $action) use ($assert): void {
    try {
        $action();
    } catch (Throwable) {
        $assert(true, 'Rejected incomplete export.');

        return;
    }
    $assert(false, 'Expected preparation to reject the invalid export.');
};
$row = static fn (string $id, ?string $closed = null): array => [
    'fsq_place_id' => $id, 'name' => 'Fixture business', 'country' => 'IL',
    'locality' => 'Unmapped locality', 'address' => null,
    'fsq_category_ids' => ['future-category'], 'fsq_category_labels' => ['Future category'],
    'date_closed' => $closed, 'latitude' => 32.1, 'longitude' => 34.8,
    'tel' => '03-1234567', 'website' => 'https://example.org', 'email' => 'contact@example.org',
    'facebook_id' => 123456789012345, 'unresolved_flags' => ['duplicate'],
];
$write = static function (string $name, array $rows) use ($directory): string {
    $path = $directory.'/'.$name.'.jsonl';
    file_put_contents($path, implode('', array_map(static fn (array $item): string => Json::encode($item)."\n", $rows)));

    return $path;
};
$preparer = new DatasetPreparer(new PlaceMapper);
$destination = $directory.'/snapshot.sqlite';
$source = $write('complete', [$row(str_repeat('a', 24)), $row(str_repeat('b', 24), '2025-01-01')]);
try {
    $result = $preparer->importJsonl($source, '2026-08-11', $destination, 2, '6981762386080966939');
    $assert($result['counts']['accepted'] === 2 && $result['counts']['closed'] === 1, 'Closed rows must remain in the complete snapshot.');
    $db = new PDO('sqlite:'.$destination);
    $metadata = $db->query('SELECT key,value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
    $record = $db->query('SELECT * FROM places ORDER BY id')->fetch(PDO::FETCH_ASSOC);
    $raw = Json::decode($record['source_metadata']);
    $assert($metadata['snapshot_id'] === '6981762386080966939' && $metadata['row_count'] === '2', 'Pinned snapshot and count must be exact.');
    $assert($metadata['provider'] === 'foursquare_places' && $metadata['status'] === 'complete', 'Reader metadata contract.');
    $assert($record['city'] === 'Unmapped locality' && $record['category_key'] === null, 'Unmapped fields do not prevent preparation.');
    $assert($raw['original_record']['unresolved_flags'] === ['duplicate'], 'Unconfirmed flags must survive without filtering.');
    $assert($raw['original_record']['facebook_id'] === 123456789012345, 'Large numeric social identifiers must remain exact.');
    $assert($raw['source_categories'][0]['label'] === 'Future category', 'Original category labels must remain available.');
    $db = null;
    $baseline = hash_file('sha256', $destination);
    foreach ([
        [$write('duplicate', [$row(str_repeat('a', 24)), $row(str_repeat('a', 24))]), 2, '2026-08-11', '2'],
        [$source, 3, '2026-08-11', '2'],
        [$source, 1, '2026-08-11', '2'],
        [$write('wrong-country', [array_replace($row(str_repeat('a', 24)), ['country' => 'US'])]), 1, '2026-08-11', '2'],
        [$write('invalid-id', [$row('bad-id')]), 1, '2026-08-11', '2'],
        [$source, 2, '2026-02-31', '2'],
        [$source, 2, '2026-08-11', '1); DROP TABLE places;'],
        [$write('same-snapshot-shrink', [$row(str_repeat('a', 24))]), 1, '2026-08-11', '6981762386080966939'],
    ] as [$path, $count, $release, $snapshot]) {
        $throws(static fn () => $preparer->importJsonl($path, $release, $destination, $count, $snapshot));
        $assert(hash_file('sha256', $destination) === $baseline, 'Failed preparation must preserve the previous snapshot byte-for-byte.');
    }
    file_put_contents($directory.'/truncated.jsonl', rtrim((string) file_get_contents($source), "\n"));
    $throws(static fn () => $preparer->importJsonl($directory.'/truncated.jsonl', '2026-08-11', $destination, 2, '3'));
    $assert(hash_file('sha256', $destination) === $baseline, 'Truncated last row must preserve the previous snapshot.');
    $replacement = $write('replacement', [$row(str_repeat('c', 24))]);
    $result = $preparer->importJsonl($replacement, '2026-09-10', $destination, 1, '3');
    $db = new PDO('sqlite:'.$destination);
    $assert($result['counts']['accepted'] === 1 && $db->query('SELECT id FROM places')->fetchColumn() === str_repeat('c', 24), 'A complete newer snapshot must atomically replace the previous file.');
    $assert($db->query("SELECT value FROM metadata WHERE key='snapshot_id'")->fetchColumn() === '3', 'Replacement must publish data and version together.');
    $db = null;

    $invalidNames = $write('invalid-names', [array_replace($row(str_repeat('d', 24)), ['name' => '?']), array_replace($row(str_repeat('e', 24), '2020-01-01'), ['name' => ''])]);
    $result = $preparer->importJsonl($invalidNames, '2026-09-10', $directory.'/invalid-names.sqlite', 2, '4');
    $assert($result['counts']['accepted'] === 2 && $result['counts']['invalid_name'] === 2 && $result['counts']['invalid_open'] === 1 && $result['counts']['closed'] === 1, 'Retain unusable names with exact, non-overlapping import exclusion counts.');
    $db = new PDO('sqlite:'.$directory.'/invalid-names.sqlite');
    $invalidRow = $db->query('SELECT name,source_metadata FROM places ORDER BY id')->fetch(PDO::FETCH_ASSOC);
    $assert($invalidRow['name'] === '?' && Json::decode($invalidRow['source_metadata'])['original_record']['name'] === '?', 'Do not invent a public business name for an invalid source record.');
    $assert(Json::decode($invalidRow['source_metadata'])['preparation_error'] === 'invalid_business_name', 'The reader must be able to quarantine invalid names before publication.');
    $db = null;

    $http = new class implements HttpClientInterface
    {
        public int $status = 200;

        public array $requests = [];

        public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
        {
            $this->requests[] = [$method, $url, $headers];

            return new HttpResponse($this->status, [], Json::encode(['metadata' => ['current-snapshot-id' => 6981762386080966939,
                'snapshots' => [['snapshot-id' => 6981762386080966939, 'timestamp-ms' => 1786479809128]]], 'config' => ['token' => 'never-export-this']]));
        }
    };
    $remote = new DatasetPreparer(new PlaceMapper, $http);
    $snapshot = $remote->snapshot('fixture-token');
    $assert($snapshot['snapshot_id'] === '6981762386080966939' && $snapshot['release'] === '2026-08-11', 'Select the current catalog snapshot, not any historical version.');
    $assert(! str_contains(Json::encode($snapshot), 'never-export-this'), 'Catalog credentials must never enter exported metadata.');
    $assert($http->requests[0][1] === DatasetPreparer::ENDPOINT.'/v1/places/namespaces/datasets/tables/places_os', 'Send credentials only to the official endpoint.');
    $assert($http->requests[0][2]['Authorization'] === 'Bearer fixture-token', 'Use the provided token only for authorization.');
    $http->status = 401;
    $throws(static fn () => $remote->snapshot('fixture-token'));
    $throws(static fn () => $remote->snapshot("invalid\ntoken"));
    $assert(glob($destination.'.stage-*') === [], 'Failed imports must remove their staging files.');
    echo 'Foursquare preparation: '.$assertions." assertions passed.\n";
} finally {
    foreach (glob($directory.'/*') as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
