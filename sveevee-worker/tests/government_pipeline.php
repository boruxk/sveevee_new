<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\CursorSourceInterface;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\SourceRecordCursor;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class GovernmentPipelineGateway implements SveeveeGateway
{
    public array $checks = [];

    public array $batches = [];

    public array $created = [];

    public bool $failNextCheck = false;

    public bool $failNextBatch = false;

    public function checkDuplicate(array $business): array
    {
        $this->checks[] = $business;
        if ($this->failNextCheck) {
            $this->failNextCheck = false;
            throw new ApiException('Fixture preflight unavailable.', 503, 'unavailable', retryable: true);
        }

        return ['matches' => []];
    }

    public function searchBusinesses(array $filters): array
    {
        throw new RuntimeException('Fresh fixture records must not require remote page searches.');
    }

    public function importBatch(array $request): array
    {
        $this->batches[] = $request;
        if ($this->failNextBatch) {
            $this->failNextBatch = false;
            throw new ApiException('Fixture batch unavailable.', 503, 'unavailable', retryable: true);
        }
        if (count($request['businesses']) < 1 || count($request['businesses']) > 10) {
            throw new RuntimeException('Government fixture batch exceeded its ten-record bound.');
        }
        $providers = array_unique(array_column(array_column($request['businesses'], 'source'), 'provider'));
        if (count($providers) !== 1 || ! in_array($providers[0], ['data_gov_ckan', 'tel_aviv_business_licenses'], true)) {
            throw new RuntimeException('A government batch mixed source jobs or lost provenance.');
        }
        $items = [];
        foreach ($request['businesses'] as $index => $business) {
            $source = $business['source'];
            $key = $source['provider'].'|'.$source['id'];
            if (isset($this->created[$key])) {
                throw new RuntimeException('A source ID was created twice: '.$key);
            }
            if (isset($business['category_key']) || ($business['address']['city'] ?? null) === 'Israel'
                || isset($business['service_areas'])) {
                throw new RuntimeException('An internal target label or invented service area leaked into business content.');
            }
            $this->created[$key] = 10000 + count($this->created);
            $items[] = ['position' => $index + 1, 'status' => 'created', 'business' => ['id' => $this->created[$key]]];
        }

        return ['items' => $items];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

/** No HTTP: the real durable page queue supplies mapped fixture records. */
final class GovernmentPipelineSource implements CursorSourceInterface
{
    private readonly SourceRecordCursor $cursor;

    private readonly string $scanKey;

    public function __construct(private readonly string $provider, private readonly array $rows, WorkerRepository $repository)
    {
        $this->cursor = $repository->sourceRecordCursor();
        $this->scanKey = 'pipeline-fixture:'.$provider;
        $this->cursor->open($this->scanKey, $provider, ['records'], 86400);
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function refreshAfterDays(): int
    {
        return 365;
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        if ($target->fullSourceProvider() !== $this->provider) {
            throw new RuntimeException('The source received another job target.');
        }
        $emitted = 0;
        while (($scope = $this->cursor->currentScope($this->scanKey)) !== null) {
            $state = $this->cursor->state($this->scanKey, $scope);
            $pending = $this->cursor->pending($this->scanKey, $scope);
            if ($pending === []) {
                $offset = (int) $state['next_offset'];
                $page = array_map(static fn (array $row): array => [
                    'id' => $row['source_metadata']['source_id'], 'record' => $row,
                ], array_slice($this->rows, $offset, 10));
                $this->cursor->append($this->scanKey, $scope, $offset, count($this->rows), $page, Clock::now(), 1048576);

                continue;
            }
            foreach ($pending as $entry) {
                yield [...$entry['record'], '__fixture_cursor' => [
                    'scope' => $scope, 'position' => $entry['position'], 'cycle' => (int) $state['cycle'],
                ]];
                if (++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    public function acknowledge(array $raw): void
    {
        $position = $raw['__fixture_cursor'];
        $this->cursor->acknowledge($this->scanKey, $position['scope'], $position['position'], $position['cycle']);
    }
}

final class GovernmentPipelineFixture
{
    public readonly Database $database;

    public readonly BusinessNormalizer $normalizer;

    public readonly GovernmentPipelineGateway $gateway;

    public readonly Logger $logger;

    public array $rows = [];

    private array $repositories = [];

    public function __construct(string $directory, array $counts, ?Closure $change)
    {
        mkdir($directory, 0700, true);
        $this->database = new Database(':memory:');
        $this->normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa', 'Tel Aviv']);
        $this->gateway = new GovernmentPipelineGateway;
        $this->logger = new Logger($directory.'/worker.log');
        foreach ($counts as $provider => $count) {
            $this->rows[$provider] = [];
            for ($number = 1; $number <= $count; $number++) {
                $raw = self::row($provider, $number);
                $this->rows[$provider][] = $change === null ? $raw : $change($raw, $number, $provider);
            }
        }
    }

    private static function row(string $provider, int $number): array
    {
        if ($provider === 'data_gov_ckan') {
            $resource = 'f004176c-b85f-4542-8901-7b3176f9a054';
            $company = 511000000 + $number;
            $id = $resource.':'.$company;
            $url = 'https://data.gov.il/api/3/action/datastore_search?'.http_build_query([
                'resource_id' => $resource, 'limit' => 1, 'filters' => Json::encode(['מספר חברה' => $company]),
            ], '', '&', PHP_QUERY_RFC3986);
        } else {
            $where = 'ms_esek_rashi='.(1000 + $number)." AND ms_esek_mishne=0 AND mahuiot='402100'";
            $id = '964:'.hash('sha256', $where);
            $url = 'https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964/query?'.http_build_query([
                'where' => $where, 'outFields' => '*', 'returnGeometry' => 'false', 'f' => 'json',
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'name' => 'Shared original chain', 'phone' => '03-0000000', 'contact_email' => 'chain@example.com',
            'website' => 'https://chain.example.com',
            'address' => $number % 2 === 0 ? ['city' => 'Unlisted original locality', 'street' => 'Same road'] : [],
            'source_name' => $provider, 'source_url' => $url, 'source_checked_at' => Clock::now(),
            'source_metadata' => ['source_id' => $id, 'original_name' => 'Shared original chain', 'original_activity' => 'unmapped'],
        ];
    }

    public function repository(string $provider): WorkerRepository
    {
        return $this->repositories[$provider] ??= new WorkerRepository($this->database, $this->normalizer, new BusinessMerger, [$provider]);
    }

    public function run(string $provider, int $limit = 10): array
    {
        $repository = $this->repository($provider);
        $source = new GovernmentPipelineSource($provider, $this->rows[$provider], $repository);
        $target = ResearchTarget::sourceAll($provider);
        $report = new RunReport(Uuid::v4(), 'run', false);
        $report->enableSourceMetrics();
        $repository->startRun($report->runId, 'run', false, 'government-pipeline-fixture');
        $research = new ResearchService([$source], [], [$target], $this->normalizer, $repository, $this->logger, $limit, 1);
        $error = null;
        try {
            (new ImportService($this->gateway, $repository, new BusinessMerger, $this->logger, 10))
                ->runTargets($report->runId, [$target], $limit, 1, $limit, false, $report, $research);
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            $research->reportProgress($report);
        }
        $result = $report->toArray($error === null ? 'completed' : 'failed');
        $repository->finishRun($report->runId, $result['status'], null, $result, $error?->getMessage());

        return [$result, $error];
    }

    public function state(string $provider): array
    {
        return $this->repository($provider)->sourceRecordCursor()->state('pipeline-fixture:'.$provider, 'records');
    }

    public function scalar(string $query): mixed
    {
        return $this->database->pdo->query($query)->fetchColumn();
    }
}

$directories = [];
$root = sys_get_temp_dir().'/sveevee-government-pipeline-'.bin2hex(random_bytes(8));
$fixture = static function (array $counts, ?Closure $change = null) use ($root, &$directories): GovernmentPipelineFixture {
    $directory = $root.'/case-'.count($directories);
    $directories[] = $directory;

    return new GovernmentPipelineFixture($directory, $counts, $change);
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$tests = [];
foreach (['data_gov_ckan', 'tel_aviv_business_licenses'] as $provider) {
    $tests[$provider.' imports 25 distinct incomplete branches in 10, 10, 5 sequential writes'] = static function () use ($provider, $fixture, $assert): void {
        $f = $fixture([$provider => 25]);
        foreach ([10, 10, 5, 0] as $index => $expected) {
            [$report, $error] = $f->run($provider);
            $assert($error === null && $report['imported'] === $expected && $report['found'] === $expected && $report['failed'] === 0, 'Wrong sequential successful-write budget.');
            $assert((int) $f->state($provider)['consumed_offset'] === min(25, ($index + 1) * 10), 'Cursor did not stop at the successful-write boundary.');
            $assert($report['used_sources'] === [$provider] && $report['source_requests'] === 0, 'Report mixed providers or attributed nonexistent HTTP requests.');
            $assert(! isset($report['overture_progress']), 'A government job emitted Overture snapshot progress.');
        }
        $assert(count($f->gateway->checks) === 25 && count($f->gateway->created) === 25, 'Shared contacts collapsed branches or a source was processed twice.');
        $assert(array_map(static fn (array $request): int => count($request['businesses']), $f->gateway->batches) === [10, 10, 5], 'Unexpected API batch boundaries.');
        $assert((int) $f->scalar('SELECT COUNT(*) FROM businesses') === 25 && (int) $f->scalar('SELECT COUNT(DISTINCT source_url) FROM business_sources') === 25, 'Local source-to-business coverage is incomplete.');
        foreach ($f->gateway->checks as $index => $payload) {
            $assert($payload['source']['id'] === $f->rows[$provider][$index]['source_metadata']['source_id'], 'Sequential source order changed.');
            $assert($payload['address'] === $f->rows[$provider][$index]['address'], 'Unknown or absent original location was replaced.');
            $assert(! isset($payload['category_key']) && $payload['source']['metadata']['original_activity'] === 'unmapped', 'Unclassified source content was invented or discarded.');
        }
    };

    $tests[$provider.' stops after one unavailable preflight and resumes its durable pending row'] = static function () use ($provider, $fixture, $assert): void {
        $f = $fixture([$provider => 12]);
        $f->gateway->failNextCheck = true;
        [$first, $error] = $f->run($provider);
        $assert($error instanceof ApiException && $error->status === 503 && $first['found'] === 1, 'Preflight outage did not abort promptly.');
        $assert(count($f->gateway->checks) === 1 && $f->gateway->batches === [], 'Unavailable API was repeatedly probed.');
        $assert((int) $f->state($provider)['consumed_offset'] === 1 && (int) $f->scalar("SELECT COUNT(*) FROM businesses WHERE status='pending'") === 1, 'Scanned candidate was not durably queued.');
        [$second, $error] = $f->run($provider);
        $assert($error === null && $second['imported'] === 10 && $second['found'] === 9 && $second['failed'] === 0, 'Recovery did not count the pending write toward the ten-item limit.');
        $assert($f->gateway->checks[0]['source']['id'] === $f->gateway->checks[1]['source']['id'], 'The failed preflight row was skipped on recovery.');
        [$third, $error] = $f->run($provider);
        $assert($error === null && $third['imported'] === 2 && count($f->gateway->created) === 12, 'Recovery lost the remaining source rows.');
    };

    $tests[$provider.' acknowledges an incomplete record only with its original durable rejection audit'] = static function () use ($provider, $fixture, $assert): void {
        $f = $fixture([$provider => 12], static function (array $raw, int $number): array {
            if ($number === 2) {
                unset($raw['name']);
            }

            return $raw;
        });
        [$first, $error] = $f->run($provider);
        $assert($error === null && $first['imported'] === 10 && $first['found'] === 11 && $first['incomplete'] === 1, 'Rejected row consumed a successful-write slot.');
        $assert((int) $f->state($provider)['consumed_offset'] === 11 && (int) $f->scalar('SELECT COUNT(*) FROM research_failures') === 1, 'Rejection was acknowledged without a durable audit.');
        $audit = $f->database->pdo->query('SELECT * FROM research_failures')->fetch();
        $assert($audit['adapter'] === $provider && $audit['error_code'] === 'incomplete', 'Wrong source or error code in audit.');
        $raw = Json::decode($audit['raw_json']);
        $assert($raw['source_metadata'] === $f->rows[$provider][1]['source_metadata'] && ! isset($raw['name']), 'Original rejected source data was lost.');
        $assert((int) $f->scalar("SELECT COUNT(*) FROM researched_urls WHERE status='rejected'") === 1, 'Rejection has no durable source URL decision.');
        [$second, $error] = $f->run($provider);
        $assert($error === null && $second['imported'] === 1 && $second['found'] === 1 && $second['incomplete'] === 0, 'A completed rejected row was rescanned or blocked continuation.');
    };

    $tests[$provider.' keeps its source acknowledgement before a failed local insert'] = static function () use ($provider, $fixture, $assert): void {
        $f = $fixture([$provider => 5]);
        $f->database->pdo->exec("CREATE TRIGGER fixture_insert_failure BEFORE INSERT ON businesses BEGIN SELECT RAISE(FAIL, 'Fixture database unavailable'); END");
        [$first, $error] = $f->run($provider);
        $assert($error === null && $first['found'] === 1 && $first['source_errors'] === 1 && $first['deferred_target_combinations'] === 1, 'Local storage failure did not pause the source and defer its target.');
        $assert((int) $f->state($provider)['consumed_offset'] === 0 && (int) $f->scalar('SELECT COUNT(*) FROM businesses') === 0, 'Source was acknowledged before candidate persistence.');
        $assert((int) $f->scalar("SELECT COUNT(*) FROM research_failures WHERE error_code='research_error'") === 1, 'Storage failure audit is missing.');
        $assert($f->gateway->checks === [] && $f->gateway->batches === [], 'Local persistence failure reached the remote API.');
        $f->database->pdo->exec('DROP TRIGGER fixture_insert_failure');
        [$recovered, $error] = $f->run($provider);
        $assert($error === null && $recovered['imported'] === 5 && $recovered['found'] === 5 && (int) $f->state($provider)['consumed_offset'] === 5, 'Storage recovery skipped the failed source row.');
    };

    $tests[$provider.' keeps the cursor when even a rejection audit cannot be persisted'] = static function () use ($provider, $fixture, $assert): void {
        $f = $fixture([$provider => 5], static function (array $raw, int $number): array {
            if ($number === 1) {
                unset($raw['name']);
            }

            return $raw;
        });
        $f->database->pdo->exec("CREATE TRIGGER fixture_audit_failure BEFORE INSERT ON research_failures BEGIN SELECT RAISE(FAIL, 'Fixture audit unavailable'); END");
        [$failed, $error] = $f->run($provider);
        $assert($error === null && $failed['found'] === 1 && $failed['source_errors'] === 1 && $failed['deferred_target_combinations'] === 1, 'Failed rejection audit did not stop further source work.');
        $assert((int) $f->state($provider)['consumed_offset'] === 0, 'Failed rejection audit advanced the source cursor.');
        $assert((int) $f->scalar('SELECT COUNT(*) FROM research_failures') === 0 && $f->gateway->checks === [], 'Audit failure unexpectedly persisted or reached the API.');
        $f->database->pdo->exec('DROP TRIGGER fixture_audit_failure');
        [$recovered, $error] = $f->run($provider);
        $assert($error === null && $recovered['imported'] === 4 && $recovered['incomplete'] === 1 && (int) $f->state($provider)['consumed_offset'] === 5, 'Audit recovery skipped or lost the rejected row.');
        $assert((int) $f->scalar('SELECT COUNT(*) FROM research_failures') === 1, 'Recovered rejection audit was not retained.');
    };
}

$tests['separate government jobs leave foreign pending rows and retry batches untouched'] = static function () use ($fixture, $assert): void {
    $gov = 'data_gov_ckan';
    $tel = 'tel_aviv_business_licenses';
    $f = $fixture([$gov => 12, $tel => 12]);
    $f->gateway->failNextCheck = true;
    [, $error] = $f->run($tel);
    $assert($error instanceof ApiException, 'Fixture did not establish a Tel Aviv pending candidate.');
    $foreign = iterator_to_array($f->repository($tel)->pendingForTarget(ResearchTarget::sourceAll($tel)));
    [$firstGov, $error] = $f->run($gov);
    $assert($error === null && $firstGov['imported'] === 10 && $firstGov['used_sources'] === [$gov], 'Gov run mixed source queues or report provenance.');
    $assert(count($foreign) === 1 && $f->repository($tel)->business($foreign[0]['id'])['status'] === 'pending', 'Gov consumed the Tel Aviv pending row.');

    $f->gateway->failNextBatch = true;
    [$firstTel] = $f->run($tel);
    $pending = $f->repository($tel)->pendingBatches();
    $assert($firstTel['imported'] === 0 && count($pending) === 1, 'Fixture did not persist the failed Tel Aviv batch.');
    $request = $pending[0]['request'];
    [$secondGov, $error] = $f->run($gov);
    $assert($error === null && $secondGov['imported'] === 2 && $secondGov['used_sources'] === [$gov], 'Gov retried foreign writes or counted them against its quota.');
    $stillPending = $f->repository($tel)->pendingBatches();
    $assert(count($stillPending) === 1 && $stillPending[0]['request'] === $request && (int) $stillPending[0]['attempts'] === 1, 'Gov mutated or retried the Tel Aviv idempotent batch.');
    [$retryTel, $error] = $f->run($tel);
    $assert($error === null && $retryTel['imported'] === 10 && $retryTel['found'] === 0, 'Tel Aviv retry failed to consume its full ten-write run quota.');
    $assert($f->gateway->batches[3] === $request && $f->repository($tel)->pendingBatches() === [], 'Tel Aviv retry changed its request or idempotency UUID.');
    [$lastTel, $error] = $f->run($tel);
    $assert($error === null && $lastTel['imported'] === 2 && count($f->gateway->created) === 24, 'Separate job continuations lost or merged source rows.');
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
    gc_collect_cycles();
    foreach ($directories as $directory) {
        if (is_file($directory.'/worker.log')) {
            unlink($directory.'/worker.log');
        }
        rmdir($directory);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
    restore_error_handler();
}
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
