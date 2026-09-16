<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Storage\CatalogRepairService;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class CatalogRepairGateway implements SveeveeGateway
{
    public array $pages = [];

    public array $batches = [];

    public bool $duplicate = false;

    public function checkDuplicate(array $business): array
    {
        throw new RuntimeException('Repair must retain its verified existing page ID.');
    }

    public function searchBusinesses(array $filters): array
    {
        if (array_keys($filters) !== ['id', 'per_page']) {
            throw new RuntimeException('Repairs must resolve existing pages by exact ID.');
        }

        return ['businesses' => isset($this->pages[$filters['id']]) ? [$this->pages[$filters['id']]] : []];
    }

    public function importBatch(array $request): array
    {
        $this->batches[] = $request;
        $items = [];
        foreach ($request['businesses'] as $index => $payload) {
            if (! isset($this->pages[$payload['id'] ?? 0]) || ($payload['source']['provider'] ?? null) !== 'overture_places') {
                throw new RuntimeException('Repair lost existing page or source identity.');
            }
            $items[] = $this->duplicate
                ? ['position' => $index + 1, 'status' => 'duplicate', 'matches' => [['id' => $payload['id']]]]
                : ['position' => $index + 1, 'status' => 'updated', 'business' => ['id' => $payload['id']]];
        }

        return ['items' => $items];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

final class CatalogRepairQueryDatabase extends PDO
{
    public array $cohortQueries = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'SELECT s.id AS source_row_id,')) {
            $this->cohortQueries[] = $query;
        }

        return parent::prepare($query, $options);
    }
}

final class CatalogRepairFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public readonly BusinessNormalizer $normalizer;

    public readonly BusinessMerger $merger;

    public readonly OverturePlacesSource $source;

    public readonly CatalogRepairGateway $gateway;

    public readonly string $snapshot;

    public function __construct(public readonly string $directory, array $groups)
    {
        mkdir($directory, 0700, true);
        $this->database = new Database($directory.'/worker.sqlite');
        $this->normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa']);
        $this->merger = new BusinessMerger;
        $this->repository = new WorkerRepository($this->database, $this->normalizer, $this->merger, ['overture_places']);
        $this->gateway = new CatalogRepairGateway;
        $this->snapshot = $directory.'/snapshot.sqlite';
        $snapshot = new PDO('sqlite:'.$this->snapshot, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $snapshot->exec('CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
        $snapshot->exec('CREATE TABLE places(id TEXT PRIMARY KEY,name TEXT,category_key TEXT,city TEXT,street TEXT,phone TEXT,email TEXT,website TEXT,social_links TEXT,confidence REAL,release TEXT,source_url TEXT,source_name TEXT,source_checked_at TEXT,source_metadata TEXT)');
        foreach (['schema_version' => '2', 'import_mode' => 'all_places', 'country' => 'IL', 'status' => 'complete', 'row_count' => (string) count($groups), 'release' => '2026-08-19.0'] as $key => $value) {
            $snapshot->prepare('INSERT INTO metadata VALUES(?,?)')->execute([$key, $value]);
        }
        foreach ($groups as $index => $group) {
            $id = self::id($index + 1);
            $url = self::url($id);
            $city = 'Unmapped locality';
            $street = 'Original road '.$group;
            $metadata = ['overture_id' => $id, 'gers_id' => $id, 'release' => '2026-08-19.0',
                'address' => ['country' => 'IL', 'locality' => $city], 'taxonomy' => ['primary' => 'unmapped_source_category']];
            $raw = ['name' => 'Fixture '.$group, 'address' => ['city' => $city, 'street' => $street],
                'source_url' => $url, 'source_name' => 'Overture', 'source_checked_at' => '2026-09-10T00:00:00Z', 'source_metadata' => $metadata];
            $snapshot->prepare('INSERT INTO places VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $id, $raw['name'], null, $city, $street, null, null, null, '{}', null, '2026-08-19.0', $url, 'Overture', '2026-09-10T00:00:00Z', Json::encode($metadata),
            ]);
            $saved = $this->repository->upsertCandidate($this->normalizer->normalize($raw, ResearchTarget::overtureAll(), 'overture_places'));
            $pageId = 1000 + $group;
            $this->repository->markBusiness($saved['business_id'], 'imported', $pageId, operation: 'created');
            $this->gateway->pages[$pageId] = ['id' => $pageId, 'name' => $raw['name'], 'address' => $raw['address'], 'can_update' => true];
            $this->database->pdo->prepare('INSERT OR IGNORE INTO researched_urls(adapter,url_hash,source_url,status,business_id,checked_at) VALUES(?,?,?,?,?,?)')
                ->execute(['overture_places', hash('sha256', $url), $url, 'success', $saved['business_id'], '2026-09-10T00:00:00Z']);
        }
        $snapshot = null;
        foreach ($this->database->pdo->query('SELECT id,payload_json FROM businesses')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = Json::decode($row['payload_json']);
            unset($payload['source']);
            $this->database->pdo->prepare('UPDATE businesses SET payload_json=?,payload_hash=? WHERE id=?')->execute([Json::encode($payload), Json::hash($payload), $row['id']]);
        }
        $this->database->pdo->exec("INSERT INTO source_scan_progress VALUES('completed-original-scan','last-original-id',162913,'2026-09-10T00:00:00Z')");
        $this->database->pdo->prepare('INSERT INTO import_batches(client_import_id,run_id,payload_hash,request_json,status,attempts,response_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute(['completed-history', null, 'historic-hash', '{"businesses":[]}', 'completed', 1, '{"items":[]}', 'old', 'old']);
        $this->source = new OverturePlacesSource(['database_path' => $this->snapshot, 'import_mode' => 'all_places'], dirname(__DIR__),
            new WorkerRepository(new Database(':memory:'), $this->normalizer, $this->merger, ['overture_places']));
    }

    public static function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    public static function url(string $id): string
    {
        return 'https://explore.overturemaps.org/?feature=places.place.'.$id;
    }

    public function repair(bool $apply = false, int $limit = 9000): array
    {
        return (new CatalogRepairService($this->database->pdo, $this->normalizer, $this->merger, $this->source))->run('overture_places', $apply, $limit);
    }

    public function import(): array
    {
        $report = new RunReport(Uuid::v4(), 'import', false);
        $this->repository->startRun($report->runId, 'import', false, 'fixture');
        (new ImportService($this->gateway, $this->repository, $this->merger, new Logger($this->directory.'/worker.log'), 100))
            ->runTargets($report->runId, [ResearchTarget::overtureAll()], 9000, 1, 9000, false, $report);

        return $report->toArray();
    }

    public function history(): string
    {
        $data = [];
        foreach (['business_sources', 'researched_urls', 'source_scan_progress'] as $table) {
            $data[$table] = $this->database->pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        }
        $data['batch'] = $this->database->pdo->query("SELECT * FROM import_batches WHERE client_import_id='completed-history'")->fetch(PDO::FETCH_ASSOC);

        return Json::hash($data);
    }
}

$root = sys_get_temp_dir().'/sveevee-catalog-repair-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$sequence = 0;
$fixture = static function (array $groups) use ($root, &$sequence): CatalogRepairFixture {
    return new CatalogRepairFixture($root.'/case-'.++$sequence, $groups);
};
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
        $assert(true, 'Rejected unsafe repair.');

        return;
    }
    $assert(false, 'Expected an unsafe repair to fail.');
};
$tests = [];
$tests['read-only cohort pagination uses the rowid range without sorting the provider'] = static function () use ($fixture, $assert): void {
    $f = $fixture(range(1, 205));
    $assert((int) $f->database->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='business_sources_lookup_idx'")->fetchColumn() === 1,
        'The regression fixture must include the competing adapter/source_url index.');
    $readonly = new CatalogRepairQueryDatabase('sqlite:'.$f->directory.'/worker.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $readonly->exec('PRAGMA query_only=ON');
    foreach ([false, true] as $hasTracker) {
        if ($hasTracker) {
            $f->repair(true, 1);
        }
        $before = $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach (['overture_places', 'foursquare_places'] as $provider) {
            $readonly->cohortQueries = [];
            $service = new CatalogRepairService($readonly, $f->normalizer, $f->merger, $f->source);
            $summary = $service->run($provider, limit: 2);
            if ($provider === 'overture_places' && ! $hasTracker) {
                $assert($summary['examined'] === 205 && $summary['would_queue'] === 2 && $summary['beyond_limit'] === 203 && $summary['queued'] === 0,
                    'Keyset pagination must retain all candidates across three chunks while limiting the proposed queue.');
                $assert(count($readonly->cohortQueries) === 3, 'The cohort must be read in bounded 100-row chunks.');
            }
            $assert(count($readonly->cohortQueries) > 0, 'The service must execute its cohort query for each provider.');
            foreach ([0, 100] as $last) {
                $query = $readonly->prepare('EXPLAIN QUERY PLAN '.$readonly->cohortQueries[0]);
                $query->execute([$provider, $last]);
                $details = implode(' | ', $query->fetchAll(PDO::FETCH_COLUMN, 3));
                $assert(str_contains($details, 'SEARCH s USING INTEGER PRIMARY KEY (rowid>?)') && ! str_contains($details, 'USE TEMP B-TREE'),
                    'Each actual cohort query must scan the source primary-key range without a temporary sort: '.$details);
            }
        }
        $assert($before === $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'Read-only query-plan validation and preview must not stage any business.');
    }
};
$tests['preview is read-only and staging preserves source history, cursors and completed batches'] = static function () use ($fixture, $assert): void {
    $f = $fixture([1, 2, 3]);
    $history = $f->history();
    $rows = $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $preview = $f->repair(limit: 2);
    $assert($preview['would_queue'] === 2 && $preview['queued'] === 0 && $preview['beyond_limit'] === 1, 'Preview must respect the queue limit.');
    $assert($rows === $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        && (int) $f->database->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='catalog_repairs'")->fetchColumn() === 0, 'Preview must not change the database or create the tracker.');
    $staged = $f->repair(true, 2);
    $assert($staged['queued'] === 2 && $history === $f->history(), 'Staging must leave all historical source and import progress intact.');
    $plan = $f->database->pdo->query("EXPLAIN QUERY PLAN SELECT b.request_json,b.response_json,i.position FROM import_batches b JOIN import_batch_items i ON i.client_import_id=b.client_import_id WHERE b.rowid>1 AND b.status='completed' AND i.business_id=1 AND i.payload_hash='fixture'")->fetchAll(PDO::FETCH_ASSOC);
    $assert(str_contains(Json::encode($plan), 'catalog_repair_batch_evidence_idx'), 'Completion evidence must use the business/hash index instead of scanning all historical batch items.');
    $pending = $f->repository->pendingBusinesses(10);
    $assert(count($pending) === 2 && $pending[0]['payload']['id'] === 1001 && $pending[0]['sveevee_page_id'] === 1001, 'Repair must preserve its known page association.');
    $metadata = $pending[0]['payload']['source']['metadata'];
    $assert($metadata['source_city'] === 'Unmapped locality' && $metadata['source_categories'][0]['key'] === 'unmapped_source_category'
        && $metadata['source_categories'][0]['catalog_key'] === null, 'Use the real reader annotations for unknown raw metadata.');
};
$tests['two GERS on one business stage and import sequentially and only confirmed updates unlock the next ID'] = static function () use ($fixture, $assert): void {
    $f = $fixture([1, 1]);
    $history = $f->history();
    $first = $f->repair(true);
    $assert($first['queued'] === 1 && $first['waiting'] === 1, 'Sibling source IDs must not overwrite the same pending payload.');
    $assert($f->repair(true)['queued'] === 0, 'Repeated staging must not change pending work.');
    $assert($f->import()['updated'] === 1, 'The staged source must reach the real importer as an update.');
    $second = $f->repair(true);
    $assert($second['confirmed'] === 1 && $second['queued'] === 1, 'A confirmed update must release the next GERS ID.');
    $assert($f->import()['updated'] === 1, 'The second source must update the same existing page.');
    $last = $f->repair(true);
    $assert($last['confirmed'] === 1 && $last['queued'] === 0 && (int) $f->database->pdo->query("SELECT COUNT(*) FROM catalog_repairs WHERE status='confirmed'")->fetchColumn() === 2, 'Both source IDs require independent confirmed completion.');
    $payloads = array_merge(...array_column($f->gateway->batches, 'businesses'));
    $assert(array_column(array_column($payloads, 'source'), 'id') === [CatalogRepairFixture::id(1), CatalogRepairFixture::id(2)]
        && array_column($payloads, 'id') === [1001, 1001], 'The two GERS must never become new pages or overwrite each other.');
    $assert($history === $f->history(), 'Repair/import must not mutate original source rows, cursor or completed historical batch.');
};
$tests['duplicate, wrong page and unconfirmed hashes never count as a repaired source'] = static function () use ($fixture, $assert): void {
    foreach (['duplicate', 'wrong-page', 'changed-hash'] as $mode) {
        $f = $fixture([1, 1]);
        $f->repair(true);
        $f->gateway->duplicate = $mode === 'duplicate';
        $f->import();
        if ($mode === 'wrong-page') {
            $f->database->pdo->exec('UPDATE businesses SET sveevee_page_id=9999');
        } elseif ($mode === 'changed-hash') {
            $f->database->pdo->exec("UPDATE businesses SET payload_hash='unconfirmed-new-payload'");
        }
        $summary = $f->repair(true);
        $assert($summary['confirmed'] === 0 && $summary['queued'] === 0 && $summary['blocked'] > 0, 'Only the exact staged source/hash/page successful import can unlock its sibling: '.$mode);
    }
};
$tests['pending, queued, claimed, closed and review rows remain protected'] = static function () use ($fixture, $assert): void {
    $f = $fixture([1, 2, 3, 4, 5]);
    foreach (['pending', 'queued', 'claimed', 'closed', 'review'] as $index => $status) {
        $f->database->pdo->prepare('UPDATE businesses SET status=?,last_error_code=? WHERE id=?')->execute([$status, 'retain', $index + 1]);
    }
    $before = $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result = $f->repair(true);
    $assert($result['queued'] === 0 && $result['waiting'] === 2 && $result['protected'] === 3, 'Repair must never reset active or protected decisions.');
    $assert($before === $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Protected business state changed.');
};
$tests['invalid original identity and moved snapshot locations are blocked without staging'] = static function () use ($fixture, $assert): void {
    foreach (['source-id', 'location', 'snapshot-missing'] as $mode) {
        $f = $fixture([1]);
        if ($mode === 'source-id') {
            $raw = Json::decode($f->database->pdo->query('SELECT raw_json FROM business_sources')->fetchColumn());
            $raw['source_metadata']['gers_id'] = CatalogRepairFixture::id(9);
            $f->database->pdo->prepare('UPDATE business_sources SET raw_json=?')->execute([Json::encode($raw)]);
        } else {
            $snapshot = new PDO('sqlite:'.$f->snapshot);
            $snapshot->exec($mode === 'location' ? "UPDATE places SET city='Other place'" : "UPDATE places SET id='00000000-0000-4000-8000-000000000009'");
            $snapshot = null;
        }
        $result = $f->repair(true);
        $assert($result['queued'] === 0 && $result['blocked'] === 1, 'Unverified repair must be blocked: '.$mode);
    }
};
$tests['tracker insert failure rolls back the local pending payload atomically'] = static function () use ($fixture, $assert, $throws): void {
    $f = $fixture([1]);
    $f->database->pdo->exec("UPDATE businesses SET status='claimed'");
    $f->repair(true); // Create the tracker without a queue mutation.
    $f->database->pdo->exec("UPDATE businesses SET status='imported'");
    $before = $f->database->pdo->query('SELECT * FROM businesses')->fetch(PDO::FETCH_ASSOC);
    $f->database->pdo->exec("CREATE TRIGGER reject_repair BEFORE INSERT ON catalog_repairs BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
    $throws(static fn () => $f->repair(true));
    $assert($before === $f->database->pdo->query('SELECT * FROM businesses')->fetch(PDO::FETCH_ASSOC), 'Failed tracker persistence must roll back the pending payload.');
};
$tests['mutable business success without its completed batch never confirms a source'] = static function () use ($fixture, $assert): void {
    foreach (['no-batch', 'old-batch', 'pending-batch', 'wrong-source', 'wrong-hash'] as $mode) {
        $f = $fixture([1, 1]);
        $f->repair(true);
        $business = $f->repository->business(1);
        $request = ['businesses' => [$business['payload']]];
        if ($mode === 'wrong-source') {
            $request['businesses'][0]['source']['id'] = CatalogRepairFixture::id(2);
        }
        $response = ['items' => [['position' => 1, 'status' => 'updated', 'business' => ['id' => 1001]]]];
        if ($mode !== 'no-batch') {
            $id = $mode === 'old-batch' ? 'completed-history' : 'unconfirmed-fixture';
            if ($mode !== 'old-batch') {
                $f->database->pdo->prepare('INSERT INTO import_batches(client_import_id,payload_hash,request_json,status,created_at,updated_at) VALUES(?,?,?,?,?,?)')
                    ->execute([$id, 'fixture', Json::encode($request), $mode === 'pending-batch' ? 'pending' : 'completed', 'now', 'now']);
            }
            $f->database->pdo->prepare('UPDATE import_batches SET request_json=?,response_json=? WHERE client_import_id=?')->execute([Json::encode($request), Json::encode($response), $id]);
            $f->database->pdo->prepare('INSERT INTO import_batch_items(client_import_id,position,business_id,payload_hash) VALUES(?,1,1,?)')
                ->execute([$id, $mode === 'wrong-hash' ? 'different-submitted-hash' : $business['payload_hash']]);
        }
        $f->repository->markBusiness(1, 'updated', 1001, operation: 'updated');
        $summary = $f->repair(true);
        $assert($summary['confirmed'] === 0 && $summary['queued'] === 0, 'Only a new completed batch with exact request and hash confirms repair: '.$mode);
    }
};
$tests['an old pending batch prevents staging despite a terminal business status'] = static function () use ($fixture, $assert): void {
    $f = $fixture([1]);
    $f->database->pdo->exec("UPDATE import_batches SET status='pending' WHERE client_import_id='completed-history'");
    $f->database->pdo->exec("INSERT INTO import_batch_items(client_import_id,position,business_id,payload_hash) VALUES('completed-history',1,1,'old-hash')");
    $summary = $f->repair(true);
    $assert($summary['queued'] === 0 && $summary['waiting'] === 1, 'Never replace a payload while an older batch can replay it.');
};
$tests['Foursquare resets only verified city failures once and preserves all other decisions'] = static function () use ($fixture, $assert): void {
    $f = $fixture([]);
    $repository = new WorkerRepository($f->database, $f->normalizer, $f->merger, ['foursquare_places']);
    $cases = [['failed', 'city'], ['failed', 'website'], ['review', 'review_required'], ['closed', 'source_closed'], ['claimed', 'claimed'], ['pending', null], ['failed', 'city']];
    foreach ($cases as $index => [$status, $code]) {
        $id = str_pad((string) ($index + 1), 24, '0', STR_PAD_LEFT);
        $url = 'https://foursquare.com/placemakers/review-place/'.$id;
        $raw = ['name' => 'Foursquare fixture '.$index, 'address' => ['city' => 'Unknown original city', 'street' => 'Original road '.$index],
            'source_url' => $url, 'source_name' => 'Foursquare', 'source_checked_at' => '2026-09-10T00:00:00Z',
            'source_metadata' => ['source_id' => $id, 'fsq_place_id' => $id, 'country' => 'IL', 'source_city' => 'Unknown original city',
                'source_categories' => [['key' => 'unmapped', 'label' => 'Original category', 'catalog_key' => null]], 'original_record' => ['fsq_place_id' => $id]]];
        $saved = $repository->upsertCandidate($f->normalizer->normalize($raw, ResearchTarget::sourceAll('foursquare_places'), 'foursquare_places'));
        $repository->markBusiness($saved['business_id'], $status, errorCode: $code, errorMessage: $code);
        if ($index === 6) {
            $f->database->pdo->prepare("UPDATE business_sources SET source_url='https://unverified.example/place' WHERE business_id=?")->execute([$saved['business_id']]);
        }
    }
    $history = $f->history();
    $before = $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $service = new CatalogRepairService($f->database->pdo, $f->normalizer, $f->merger);
    $summary = $service->run('foursquare_places', true);
    $after = $f->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assert($summary['queued'] === 1 && $summary['blocked'] === 1, 'Only one valid failed/city candidate qualifies.');
    $assert(array_slice($before, 1) === array_slice($after, 1), 'Unrelated failures and protected statuses must remain unchanged.');
    $assert($before[0]['payload_json'] === $after[0]['payload_json'] && $before[0]['payload_hash'] === $after[0]['payload_hash'] && $history === $f->history(), 'City reset must preserve raw payload and source history.');
    $assert($after[0]['status'] === 'pending' && $after[0]['last_error_code'] === null, 'Validated candidate must be queued locally.');
    $assert($service->run('foursquare_places', true)['queued'] === 0, 'Repeated apply cannot reset pending work.');
    $repository->markBusiness(1, 'failed', errorCode: 'city');
    $assert($service->run('foursquare_places', true)['queued'] === 0, 'One failed city repair cannot be automatically reset forever.');
};
$tests['read-only PDO preview and strict 9000 maximum are enforced'] = static function () use ($fixture, $assert, $throws): void {
    $f = $fixture([1]);
    $readonly = new PDO('sqlite:'.$f->directory.'/worker.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $readonly->exec('PRAGMA query_only=ON');
    $service = new CatalogRepairService($readonly, $f->normalizer, $f->merger, $f->source);
    $assert($service->run('overture_places')['would_queue'] === 1, 'Default preview must work with a strictly read-only database.');
    $throws(static fn () => $service->run('overture_places', limit: 9001));
    $throws(static fn () => $service->run('overture_places', limit: 0));
    $throws(static fn () => $service->run('overture_places', true));
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
    unset($f);
    gc_collect_cycles();
    $resolved = realpath($root);
    if ($resolved === false || realpath(dirname($resolved)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'sveevee-catalog-repair-')) {
        throw new RuntimeException('Unexpected catalog fixture cleanup path.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $parent = realpath(dirname($file->getPathname()));
        if ($parent === false || ($parent !== $resolved && ! str_starts_with($parent, $resolved.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Unexpected catalog fixture child path.');
        }
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    unset($files);
    rmdir($resolved);
    restore_error_handler();
}
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
