<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\FoursquareReviewReconciliation;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class ReconciliationFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public array $manifest = ['version' => 1, 'provider' => 'foursquare_places', 'decisions' => []];

    public function __construct(int $count)
    {
        $this->database = new Database(':memory:');
        $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa']);
        $this->repository = new WorkerRepository($this->database, $normalizer, new BusinessMerger, ['foursquare_places']);
        for ($index = 1; $index <= $count; $index++) {
            $id = str_pad((string) $index, 24, '0', STR_PAD_LEFT);
            $url = 'https://foursquare.com/placemakers/review-place/'.$id;
            $raw = ['name' => 'Reviewed fixture '.$index, 'address' => ['city' => 'Unmapped city', 'street' => 'Source road '.$index],
                'source_url' => $url, 'source_name' => 'Foursquare', 'source_checked_at' => '2026-09-10T00:00:00Z',
                'source_metadata' => ['source_id' => $id, 'fsq_place_id' => $id, 'country' => 'IL',
                    'source_categories' => [['key' => 'unknown_category', 'label' => 'Original category', 'catalog_key' => null]],
                    'original_record' => ['name' => 'Reviewed fixture '.$index, 'fsq_place_id' => $id]]];
            $saved = $this->repository->upsertCandidate($normalizer->normalize($raw, ResearchTarget::sourceAll('foursquare_places'), 'foursquare_places'));
            $this->repository->markBusiness($saved['business_id'], 'review', errorCode: 'review_required', errorMessage: 'Unconfirmed identity.');
            $business = $this->repository->business($saved['business_id']);
            $this->manifest['decisions'][] = ['review_id' => $index, 'source_id' => $id, 'source_url' => $url, 'page_id' => 1000 + $index,
                'operation' => 'created', 'decided_at' => '2026-09-10T12:00:00+00:00', 'source_metadata_hash' => Json::hash($business['payload']['source']['metadata'])];
        }
        $this->database->pdo->exec("INSERT INTO source_scan_progress VALUES('completed-source','last-id',109757,'old')");
        $this->database->pdo->exec("INSERT INTO import_batches(client_import_id,payload_hash,request_json,status,response_json,created_at,updated_at) VALUES('old-completed','old','{}','completed','{}','old','old')");
    }

    public function run(bool $apply = false, int $limit = 9000): array
    {
        return (new FoursquareReviewReconciliation($this->database->pdo))->run($this->manifest, $apply, $limit);
    }

    public function rows(): array
    {
        return $this->database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(): string
    {
        $data = [];
        foreach (['business_sources', 'business_identity_keys', 'researched_urls', 'source_scan_progress', 'import_batches', 'import_batch_items', 'runs', 'run_log_outbox'] as $table) {
            $data[$table] = $this->database->pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        }

        return Json::hash($data);
    }
}

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
        $assert(true, 'Invalid action rejected.');

        return;
    }
    $assert(false, 'Expected rejection.');
};
$tests = [];
$tests['preview is query-only and apply preserves payload, cursor and import history'] = static function () use ($assert): void {
    $f = new ReconciliationFixture(2);
    $before = $f->rows();
    $history = $f->history();
    $f->database->pdo->exec('PRAGMA query_only=ON');
    $preview = $f->run();
    $assert($preview['would_reconcile'] === 2 && $preview['reconciled'] === 0, 'Preview must identify committed decisions without writing.');
    $assert($f->rows() === $before && $history === $f->history(), 'Preview changed stored data.');
    $assert((int) $f->database->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='foursquare_review_reconciliations'")->fetchColumn() === 0, 'Preview must not create the journal.');
    $f->database->pdo->exec('PRAGMA query_only=OFF');
    $result = $f->run(true);
    $after = $f->rows();
    $plan = $f->database->pdo->query("EXPLAIN QUERY PLAN SELECT 1 FROM import_batch_items i JOIN import_batches b ON b.client_import_id=i.client_import_id WHERE i.business_id=1 AND b.status='pending'")->fetchAll(PDO::FETCH_ASSOC);
    $assert(str_contains(Json::encode($plan), 'foursquare_review_pending_idx'), 'Pending-batch protection must use the per-business index.');
    $assert($result['reconciled'] === 2 && $after[0]['sveevee_page_id'] === 1001 && $after[0]['status'] === 'imported', 'Apply must adopt the explicitly created backend page.');
    $assert($before[0]['payload_json'] === $after[0]['payload_json'] && $before[0]['payload_hash'] === $after[0]['payload_hash'] && $before[0]['attempts'] === $after[0]['attempts'], 'A reconciliation must not rewrite data or fabricate an API attempt.');
    $assert($history === $f->history(), 'Source data, cursor, previous batches or run reports changed.');
    $receipt = $f->database->pdo->query('SELECT * FROM foursquare_review_reconciliations ORDER BY review_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assert(Json::decode($receipt['before_json']) === $before[0], 'Receipt must retain the complete original local state for a reviewed rollback.');
};
$tests['replay is idempotent and bounded runs advance only unprocessed decisions'] = static function () use ($assert): void {
    $f = new ReconciliationFixture(3);
    $first = $f->run(true, 1);
    $assert($first['reconciled'] === 1 && $first['beyond_limit'] === 2, 'The selected limit must bound actual local changes.');
    $second = $f->run(true, 1);
    $assert($second['reconciled'] === 1 && $second['already_reconciled'] === 1, 'A repeated bounded manifest must progress without replay writes.');
    $f->run(true);
    $rows = $f->rows();
    $last = $f->run(true);
    $assert($last['already_reconciled'] === 3 && $last['reconciled'] === 0 && $rows === $f->rows(), 'Repeated decisions must remain a no-op.');
    $f->manifest['decisions'][0]['page_id'] = 9999;
    $assert($f->run(true)['blocked'] === 1, 'Changed evidence cannot retarget an already reconciled source.');
};
$tests['closed claimed pending queued and other failures remain untouched'] = static function () use ($assert): void {
    $f = new ReconciliationFixture(6);
    foreach (['closed', 'claimed', 'pending', 'queued', 'failed', 'duplicate'] as $index => $status) {
        $f->database->pdo->prepare('UPDATE businesses SET status=? WHERE id=?')->execute([$status, $index + 1]);
    }
    $before = $f->rows();
    $result = $f->run(true);
    $assert($result['reconciled'] === 0 && $result['protected'] === 2 && $result['waiting'] === 2 && $result['blocked'] === 2, 'Only unresolved review decisions may be adopted.');
    $assert($before === $f->rows(), 'Protected statuses were changed.');
};
$tests['open historical batches block reconciliation and remain unchanged'] = static function () use ($assert): void {
    $f = new ReconciliationFixture(1);
    $f->database->pdo->exec("UPDATE import_batches SET status='pending'");
    $f->database->pdo->exec("INSERT INTO import_batch_items(client_import_id,position,business_id,payload_hash) VALUES('old-completed',1,1,'historic-hash')");
    $history = $f->history();
    $assert($f->run(true)['waiting'] === 1 && $f->history() === $history, 'An unresolved old request must not be bypassed.');
};
$tests['changed metadata wrong provider source IDs page conflicts and ambiguous rows are blocked'] = static function () use ($assert): void {
    foreach (['metadata', 'source-id', 'provider', 'page-id', 'ambiguous', 'missing'] as $mode) {
        $f = new ReconciliationFixture(2);
        if ($mode === 'metadata') {
            $f->manifest['decisions'][0]['source_metadata_hash'] = str_repeat('a', 64);
        } elseif ($mode === 'page-id') {
            $f->database->pdo->exec('UPDATE businesses SET sveevee_page_id=9999 WHERE id=1');
        } elseif ($mode === 'ambiguous') {
            $f->database->pdo->exec('UPDATE business_sources SET source_url=(SELECT source_url FROM business_sources WHERE business_id=1) WHERE business_id=2');
        } elseif ($mode === 'missing') {
            $f->database->pdo->exec('DELETE FROM business_sources WHERE business_id=1');
        } else {
            $payload = $f->repository->business(1)['payload'];
            $payload['source'][$mode === 'provider' ? 'provider' : 'id'] = $mode === 'provider' ? 'overture_places' : str_repeat('f', 24);
            $f->database->pdo->prepare('UPDATE businesses SET payload_json=?,payload_hash=? WHERE id=1')->execute([Json::encode($payload), Json::hash($payload)]);
        }
        $f->manifest['decisions'] = [$f->manifest['decisions'][0]];
        $before = $f->rows();
        $assert($f->run(true)['blocked'] === 1 && $before === $f->rows(), 'Unverified mapping was not blocked: '.$mode);
    }
};
$tests['invalid manifest is rejected completely before any local write'] = static function () use ($assert, $throws): void {
    foreach (['version', 'provider', 'duplicate', 'operation', 'url', 'date', 'limit'] as $mode) {
        $f = new ReconciliationFixture(2);
        if ($mode === 'version') {
            $f->manifest['version'] = 2;
        } elseif ($mode === 'provider') {
            $f->manifest['provider'] = 'overture_places';
        } elseif ($mode === 'duplicate') {
            $f->manifest['decisions'][1] = $f->manifest['decisions'][0];
        } elseif ($mode === 'operation') {
            $f->manifest['decisions'][1]['operation'] = 'pending';
        } elseif ($mode === 'url') {
            $f->manifest['decisions'][1]['source_url'] = 'https://unverified.example/';
        } elseif ($mode === 'date') {
            $f->manifest['decisions'][1]['decided_at'] = '2026-02-30T12:00:00Z';
        }
        $before = $f->rows();
        $throws(static fn () => $f->run(true, $mode === 'limit' ? 9001 : 9000));
        $assert($before === $f->rows(), 'Invalid manifest partially changed worker data.');
    }
};
$tests['failed receipt persistence atomically rolls back the business association'] = static function () use ($assert, $throws): void {
    $f = new ReconciliationFixture(1);
    $manifest = $f->manifest;
    $f->manifest['decisions'] = [];
    $f->run(true);
    $f->manifest = $manifest;
    $f->database->pdo->exec("CREATE TRIGGER reject_reconciliation BEFORE INSERT ON foursquare_review_reconciliations BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
    $before = $f->rows();
    $throws(static fn () => $f->run(true));
    $assert($f->rows() === $before, 'A journal failure must roll back the page association.');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL);
    }
}
restore_error_handler();
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
