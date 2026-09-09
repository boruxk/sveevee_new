<?php

declare(strict_types=1);

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Pipeline\ResearchTargetScheduler;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

final class PipelineSource implements SourceAdapterInterface
{
    public array $calls = [];

    public function __construct(public array $counts = [], public array $records = []) {}

    public function name(): string
    {
        return 'pipeline_fixture';
    }

    public function refreshAfterDays(): int
    {
        return 365;
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        $this->calls[] = $target->key();
        if (isset($this->records[$target->key()])) {
            yield from $this->records[$target->key()];

            return;
        }
        for ($index = 1; $index <= min($limit, $this->counts[$target->key()] ?? 0); $index++) {
            yield pipelineRaw($target, $target->key().' Business '.$index);
        }
    }
}

function pipelineRaw(ResearchTarget $target, string $name): array
{
    return [
        'name' => $name,
        'public_description' => 'Verified fixture description',
        'category_key' => $target->categoryKey,
        'address' => ['city' => $target->city],
        'source_name' => 'Pipeline fixture',
        'source_url' => 'https://example.com/pipeline/'.hash('sha256', $name),
        'source_checked_at' => Clock::now(),
    ];
}

function pipelineRun(
    WorkerRepository $repository,
    Logger $logger,
    array $targets,
    PipelineSource $source,
    int $limit = 1000,
    int $productiveLimit = 10,
    int $perCombination = 100,
    bool $dryRun = false,
    ?FakeGateway $gateway = null,
): array {
    $gateway ??= new FakeGateway;
    $report = new RunReport(Uuid::v4(), 'run', $dryRun);
    $repository->startRun($report->runId, 'run', $dryRun, 'fixture');
    $ordered = (new ResearchTargetScheduler($repository))->next($targets, count($targets));
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, array_unique(array_map(
        static fn (ResearchTarget $target): string => $target->city, $targets,
    )));
    $research = new ResearchService([$source], [], $ordered, $normalizer, $repository, $logger, $perCombination, $productiveLimit);
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100))->runTargets(
        $report->runId, $ordered, $limit, $productiveLimit, $perCombination, $dryRun, $report, $research,
    );

    return [$report->toArray(), $gateway];
}

$test('run skips an empty city, fills 1000 writes in ten combinations, then resumes at the next city', function () use ($assert): void {
    $cities = ['Empty City', 'First City', 'Next City'];
    [$repository, $logger, $directory] = testRepository($cities);
    $targets = [];
    $counts = [];
    foreach ($cities as $city) {
        foreach (range(1, 10) as $category) {
            $target = new ResearchTarget($city, 'category.'.$category);
            $targets[] = $target;
            $counts[$target->key()] = $city === 'Empty City' ? 0 : 125;
        }
    }
    $source = new PipelineSource($counts);
    [$report, $gateway] = pipelineRun($repository, $logger, $targets, $source);
    $assert($report['imported'] === 1000);
    $assert($report['productive_target_combinations'] === 10);
    $assert($report['scanned_target_combinations'] === 20);
    $assert($report['empty_target_combinations'] === 10);
    $assert(count($source->calls) === 20);
    $assert(array_unique(array_map(static fn (array $request): int => count($request['businesses']), $gateway->batchRequests)) === [100]);
    $assert(array_unique(array_column(array_slice($report['targets'], 10), 'successful')) === [100]);

    unset($repository);
    $repository = new WorkerRepository(new Database($directory.'/worker.sqlite'), new BusinessNormalizer(new OpeningHoursParser, $cities), new BusinessMerger);
    $source = new PipelineSource($counts);
    [$next] = pipelineRun($repository, $logger, $targets, $source);
    $assert($next['imported'] === 1000);
    $assert(count($source->calls) === 10);
    $assert($source->calls[0] === $targets[20]->key(), 'Persisted cursor must continue with the next unvisited city.');
    $ordered = (new ResearchTargetScheduler($repository))->next($targets, 1);
    $assert($ordered[0]->city === 'Empty City', 'A completed round restarts with its oldest combination.');
});

$test('partial combinations count once and empty combinations never use a productive slot', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $targets = array_map(static fn (int $n): ResearchTarget => new ResearchTarget('Tel Aviv', 'category.'.$n), range(1, 5));
    $source = new PipelineSource(array_combine(array_map(static fn (ResearchTarget $t): string => $t->key(), $targets), [0, 2, 0, 3, 50]));
    [$report] = pipelineRun($repository, $logger, $targets, $source, productiveLimit: 2);
    $assert($report['imported'] === 5);
    $assert($report['productive_target_combinations'] === 2);
    $assert($report['empty_target_combinations'] === 2);
    $assert(count($source->calls) === 4);
});

$test('an entirely empty schedule finishes after exactly one finite pass', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $targets = array_map(static fn (int $n): ResearchTarget => new ResearchTarget('Tel Aviv', 'category.'.$n), range(1, 40));
    $source = new PipelineSource;
    [$report, $gateway] = pipelineRun($repository, $logger, $targets, $source);
    $assert(count($source->calls) === 40);
    $assert($report['empty_target_combinations'] === 40);
    $assert($report['productive_target_combinations'] === 0);
    $assert($report['imported'] === 0 && $gateway->batchRequests === []);
    $assert(count($repository->researchTargetProgress()) === 40);
});

$test('more than 100 duplicate and invalid candidates do not exhaust the successful-write quota', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
    $duplicate = pipelineRaw($target, 'Repeated Business');
    $records = array_fill(0, 130, $duplicate);
    for ($n = 1; $n <= 130; $n++) {
        $records[] = [...pipelineRaw($target, 'Invalid '.$n), 'name' => ''];
    }
    foreach (['Claimed Business', 'Remote Duplicate', 'Remote Invalid', 'Remote Claimed'] as $name) {
        $records[] = pipelineRaw($target, $name);
    }
    for ($n = 1; $n <= 110; $n++) {
        $records[] = pipelineRaw($target, 'Valid Business '.$n);
    }
    $source = new PipelineSource(records: [$target->key() => $records]);
    $gateway = new FakeGateway;
    $gateway->itemStatuses = ['Remote Duplicate' => 'duplicate', 'Remote Invalid' => 'invalid', 'Remote Claimed' => 'claimed'];
    [$report] = pipelineRun($repository, $logger, [$target], $source, gateway: $gateway);
    $assert($report['imported'] === 100);
    $assert($report['incomplete'] === 130);
    $assert($report['duplicates'] >= 130);
    $assert($report['failed'] === 1);
    $assert($report['found'] > 360);
    $assert(array_map(static fn (array $request): int => count($request['businesses']), $gateway->batchRequests) === [100, 3]);
    $assert($report['productive_target_combinations'] === 1);
});

$test('pending retries keep their UUID and obey both the CLI and combination budget', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
    for ($n = 1; $n <= 120; $n++) {
        $repository->upsertCandidate(testCandidate('Pending '.$n));
    }
    $firstGateway = new FakeGateway;
    $firstGateway->failNextBatch = true;
    pipelineRun($repository, $logger, [$target], new PipelineSource, gateway: $firstGateway);
    $persisted = $firstGateway->batchRequests[0];

    [$small, $smallGateway] = pipelineRun($repository, $logger, [$target], new PipelineSource, limit: 30);
    $assert($smallGateway->batchRequests === [], 'An intact 100-item retry cannot run with --limit=30.');
    $assert(($small['errors'][0]['stage'] ?? null) === 'budget_deferred');

    [$retry, $retryGateway] = pipelineRun($repository, $logger, [$target], new PipelineSource, limit: 100);
    $assert($retryGateway->batchRequests === [$persisted], 'Retry must reuse the exact persisted UUID and body.');
    $assert($retry['imported'] === 100);
    $assert(($repository->statusSummary()['businesses']['pending'] ?? 0) === 20);
    [$last] = pipelineRun($repository, $logger, [$target], new PipelineSource);
    $assert($last['imported'] === 20, 'No daily quota can suppress a later run.');
});

$test('pending batches from eleven combinations cannot bypass the ten productive combination cap', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $targets = [];
    $runId = Uuid::v4();
    $repository->startRun($runId, 'run', false, 'fixture');
    foreach (range(1, 11) as $n) {
        $target = new ResearchTarget('Tel Aviv', 'category.'.$n);
        $targets[] = $target;
        $stored = $repository->upsertCandidate(testCandidateFor('Retry combo '.$n, $target->city, $target->categoryKey));
        $business = $repository->business($stored['business_id']);
        $repository->createBatch($runId, [['business_id' => $business['id'], 'payload' => $business['payload']]]);
    }
    [$report, $gateway] = pipelineRun($repository, $logger, $targets, new PipelineSource);
    $assert($report['imported'] === 10 && count($gateway->batchRequests) === 10);
    $assert($report['productive_target_combinations'] === 10);
    $assert(count($repository->pendingBatches()) === 1);
});

$test('dry run budgets pending retries and fresh rows without writes or cursor changes', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
    $runId = Uuid::v4();
    $repository->startRun($runId, 'run', false, 'fixture');
    $items = [];
    foreach (range(1, 10) as $n) {
        $stored = $repository->upsertCandidate(testCandidate('Dry pending '.$n));
        $business = $repository->business($stored['business_id']);
        if ($n <= 5) {
            $items[] = ['business_id' => $business['id'], 'payload' => $business['payload']];
        }
    }
    $repository->createBatch($runId, $items);
    [$report, $gateway] = pipelineRun($repository, $logger, [$target], new PipelineSource, limit: 7, dryRun: true);
    $assert($gateway->batchRequests === []);
    $assert($report['planned_imports'] === 7 && $report['imported'] === 0);
    $assert(($report['targets'][0]['planned'] ?? 0) === 7);
    $assert($repository->researchTargetProgress() === []);
    $status = $repository->statusSummary()['businesses'];
    $assert($status['queued'] === 5 && $status['pending'] === 5);
});

$test('richer repeated source data survives stale created and duplicate batch responses for the next run', function () use ($assert): void {
    foreach (['created', 'duplicate'] as $responseStatus) {
        [$repository, $logger] = testRepository();
        $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
        $old = pipelineRaw($target, 'Enriched Business');
        $new = [...$old, 'phone' => '03-1234567'];
        $source = new PipelineSource(records: [$target->key() => [$old, $new, pipelineRaw($target, 'Other Business')]]);
        $gateway = new FakeGateway;
        $gateway->itemStatuses['Enriched Business'] = $responseStatus;
        [$report] = pipelineRun($repository, $logger, [$target], $source, gateway: $gateway);
        $pending = $repository->pendingBusinesses(10);
        $assert(count($pending) === 1 && $pending[0]['payload']['name'] === 'Enriched Business');
        $assert(isset($pending[0]['payload']['phone']));
        $assert(count($gateway->batchRequests) === 1);
        $assert(count($gateway->batchRequests[0]['businesses']) === 2, 'Repeated richer records must not write one business twice.');
        $assert(! isset($gateway->batchRequests[0]['businesses'][0]['phone']), 'Fixture must exercise an older prepared snapshot.');
    }
});

$test('a queued snapshot retains its original category and preserves richer data after replay', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $first = new ResearchTarget('Tel Aviv', 'category.first');
    $second = new ResearchTarget('Tel Aviv', 'category.second');
    $stored = $repository->upsertCandidate(testCandidateFor('Moved Business', $first->city, $first->categoryKey));
    $business = $repository->business($stored['business_id']);
    $runId = Uuid::v4();
    $repository->startRun($runId, 'run', false, 'fixture');
    $repository->createBatch($runId, [['business_id' => $business['id'], 'payload' => $business['payload']]]);
    $reflection = new ReflectionProperty($repository, 'pdo');
    $pdo = $reflection->getValue($repository);
    $payload = [...$business['payload'], 'category_key' => $second->categoryKey, 'phone' => '+97231234567'];
    $statement = $pdo->prepare('UPDATE businesses SET payload_json = ?, payload_hash = ? WHERE id = ?');
    $statement->execute([json_encode($payload), Json::hash($payload), $business['id']]);
    [$report, $gateway] = pipelineRun($repository, $logger, [$first, $second], new PipelineSource);
    $assert(count($gateway->batchRequests) === 1);
    $assert($report['imported'] === 1 && $report['targets'][0]['category_key'] === $first->categoryKey);
    $assert(($repository->statusSummary()['businesses']['pending'] ?? 0) === 1, 'Changed retry business must remain pending until another run.');
});

$test('legacy SQLite batch metadata migrates without changing persisted requests', function () use ($assert): void {
    [$repository, $logger, $directory] = testRepository();
    $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
    $stored = $repository->upsertCandidate(testCandidate('Legacy Business'));
    $business = $repository->business($stored['business_id']);
    $runId = Uuid::v4();
    $repository->startRun($runId, 'run', false, 'fixture');
    $batch = $repository->createBatch($runId, [['business_id' => $business['id'], 'payload' => $business['payload']]]);
    $pdo = (new ReflectionProperty($repository, 'pdo'))->getValue($repository);
    foreach (['payload_hash', 'target_city', 'target_category'] as $column) {
        $pdo->exec('ALTER TABLE import_batch_items DROP COLUMN '.$column);
    }
    unset($pdo, $repository);
    $repository = new WorkerRepository(new Database($directory.'/worker.sqlite'), new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv']), new BusinessMerger);
    $pending = $repository->pendingBatches();
    $assert($pending[0]['request'] === $batch['request']);
    $assert(array_key_exists('payload_hash', $pending[0]['items'][0]) && $pending[0]['items'][0]['payload_hash'] === null);
    [$report, $gateway] = pipelineRun($repository, $logger, [$target], new PipelineSource);
    $assert($gateway->batchRequests === [$batch['request']]);
    $assert($report['imported'] === 1);
    $assert(($repository->statusSummary()['businesses']['pending'] ?? 0) === 1, 'Unknown legacy snapshot is rechecked conservatively next run.');
});

$test('successful updates share the smaller CLI limit with creates', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
    $records = [pipelineRaw($target, 'Existing Business')];
    foreach (range(1, 20) as $n) {
        $records[] = pipelineRaw($target, 'CLI limited '.$n);
    }
    [$report, $gateway] = pipelineRun($repository, $logger, [$target], new PipelineSource(records: [$target->key() => $records]), limit: 7);
    $assert($report['updated'] === 1 && $report['imported'] === 6);
    $assert($report['found'] === 7 && count($gateway->batchRequests[0]['businesses']) === 7);
});

$test('a combination containing only a claimed page does not consume a productive slot', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $first = new ResearchTarget('Tel Aviv', 'category.first');
    $second = new ResearchTarget('Tel Aviv', 'category.second');
    $source = new PipelineSource([$second->key() => 2], [$first->key() => [pipelineRaw($first, 'Claimed Business')]]);
    [$report] = pipelineRun($repository, $logger, [$first, $second], $source, productiveLimit: 1);
    $assert($report['imported'] === 2 && $report['productive_target_combinations'] === 1);
    $assert($report['unproductive_target_combinations'] === 1 && count($source->calls) === 2);
});

$test('dry research and import stop at planned capacity without changing the cursor or remote data', function () use ($assert): void {
    [$repository, $logger] = testRepository();
    $target = new ResearchTarget('Tel Aviv', 'professionals.electricians');
    [$report, $gateway] = pipelineRun($repository, $logger, [$target], new PipelineSource([$target->key() => 20]), limit: 7, dryRun: true);
    $assert($report['found'] === 7 && $report['planned_imports'] === 7);
    $assert($report['imported'] === 0 && $gateway->batchRequests === []);
    $assert(($repository->statusSummary()['businesses']['pending'] ?? 0) === 7);
    $assert($repository->researchTargetProgress() === []);
});

$test('legacy daily quota in a loaded config has no effect on successive run budgets', function () use ($assert): void {
    [$repository, $logger, $directory] = testRepository();
    $path = $directory.'/legacy-config.json';
    file_put_contents($path, json_encode([
        'target_per_run' => 10,
        'targets_per_run' => 10,
        'businesses_per_combination' => 100,
        'cities' => ['Tel Aviv'],
        'categories' => ['professionals.electricians'],
        'quotas' => ['max_new_per_day' => 1],
        'storage' => ['database' => 'worker.sqlite', 'reports_dir' => 'reports', 'log_file' => 'worker.log'],
    ], JSON_THROW_ON_ERROR));
    $config = WorkerConfig::load($path, $directory);
    $targets = $config->targets();
    foreach ([1, 2] as $run) {
        [$report] = pipelineRun(
            $repository, $logger, $targets, new PipelineSource([$targets[0]->key() => 30]),
            $config->int('target_per_run', 1000), $config->int('targets_per_run', 10),
            $config->int('businesses_per_combination', 100),
        );
        $assert($report['imported'] === 10);
    }
    $assert(($repository->statusSummary()['businesses']['imported'] ?? 0) === 20);
});
