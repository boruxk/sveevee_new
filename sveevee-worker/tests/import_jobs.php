<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
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
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class ImportJobGateway implements SveeveeGateway
{
    public array $requests = [];

    public int $duplicateChecks = 0;

    private int $nextPageId = 100000;

    public function checkDuplicate(array $business): array
    {
        $this->duplicateChecks++;

        return ['matches' => []];
    }

    public function searchBusinesses(array $filters): array
    {
        throw new RuntimeException('The fixture has no remote lookup candidates.');
    }

    public function importBatch(array $request): array
    {
        $rows = $request['businesses'];
        if (count($rows) < 1 || count($rows) > 100) {
            throw new RuntimeException('The pipeline sent an API batch outside the 1..100 limit.');
        }
        $combinations = array_unique(array_map(static fn (array $row): string =>
            $row['address']['city'].'|'.$row['category_key'], $rows));
        if (count($combinations) !== 1) {
            throw new RuntimeException('An API batch mixed city-category combinations.');
        }
        $this->requests[] = $request;
        $items = [];
        foreach ($rows as $index => $row) {
            $status = str_starts_with($row['name'], 'Duplicate fixture ')
                ? 'duplicate'
                : (str_starts_with($row['name'], 'Invalid fixture ') ? 'invalid' : 'created');
            $items[] = [
                'position' => $index + 1,
                'status' => $status,
                'business' => ['id' => $this->nextPageId++],
            ];
        }

        return ['client_import_id' => $request['client_import_id'], 'items' => $items];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

final class ImportJobSource implements SourceAdapterInterface
{
    public array $visited = [];

    public function __construct(
        private readonly string $adapter,
        private readonly array $counts,
        private readonly bool $includeRejectedRows = false,
    ) {}

    public function name(): string
    {
        return $this->adapter;
    }

    public function refreshAfterDays(): int
    {
        return 365;
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        $this->visited[] = $target->key();
        $count = min($limit, $this->counts[$target->key()] ?? 0);
        for ($number = 1; $number <= $count; $number++) {
            $kind = $this->includeRejectedRows && $number <= 10
                ? 'Duplicate'
                : ($this->includeRejectedRows && $number <= 15 ? 'Invalid' : 'Business');
            yield [
                'name' => $kind.' fixture '.$target->key().' '.$number,
                'category_key' => $target->categoryKey,
                'address' => ['city' => $target->city, 'street' => 'Fixture Street', 'number' => (string) $number],
                'source_name' => $this->adapter,
                'source_url' => 'https://example.org/import-job/'.hash('sha256', $target->key().'|'.$number),
            ];
        }
    }
}

$directories = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $profile, array $targets, ImportJobSource $source) use (&$directories): array {
    $settings = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/'.$profile));
    $directory = sys_get_temp_dir().'/sveevee-import-jobs-'.bin2hex(random_bytes(8));
    $database = new Database($directory.'/worker.sqlite');
    $directories[] = $directory;
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, array_values(array_unique(array_map(
        static fn (ResearchTarget $target): string => $target->city, $targets,
    ))));
    $repository = new WorkerRepository($database, $normalizer, new BusinessMerger);
    $logger = new Logger($directory.'/worker.log', false);
    $gateway = new ImportJobGateway;
    $report = new RunReport(Uuid::v4(), 'run', false);
    $repository->startRun($report->runId, 'run', false, Json::hash($settings));
    $scheduled = (new ResearchTargetScheduler($repository))->next($targets, count($targets));
    $research = new ResearchService(
        [$source], [], $scheduled, $normalizer, $repository, $logger,
        $settings['businesses_per_combination'], $settings['targets_per_run'],
    );
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, $settings['batch_size']))->runTargets(
        $report->runId, $scheduled, $settings['target_per_run'], $settings['targets_per_run'],
        $settings['businesses_per_combination'], false, $report, $research,
    );

    return [$repository, $database, $gateway, $report->toArray()];
};

$tests = [];
$tests['Overture imports 9000 actual rows in 90 batches and stops before row 9001'] = static function () use ($run, $assert): void {
    $target = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
    $source = new ImportJobSource('overture_places', [$target->key() => 9001]);
    [$repository, $database, $gateway, $report] = $run('worker.overture.json', [$target], $source);

    $sizes = array_map(static fn (array $request): int => count($request['businesses']), $gateway->requests);
    $assert($sizes === array_fill(0, 90, 100), 'Overture must send exactly 90 complete 100-row batches.');
    $assert($report['imported'] === 9000 && $report['updated'] === 0 && $report['failed'] === 0, 'The report must reflect 9000 successful imports.');
    $assert($gateway->duplicateChecks === 9000 && $report['found'] === 9000, 'The pipeline must stop fetching and preparing rows after 9000 successes.');
    $assert($report['productive_target_combinations'] === 1 && $report['targets'][0]['successful'] === 9000, 'One productive Overture combination must support the full 9000-row budget.');
    $assert(($repository->statusSummary()['businesses']['imported'] ?? 0) === 9000, '9000 successful business rows must exist in SQLite.');
    $assert((int) $database->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 9000, 'Row 9001 must remain unconsumed at the source.');
    $assert((int) $database->pdo->query("SELECT COUNT(*) FROM import_batches WHERE status = 'completed'")->fetchColumn() === 90, 'All 90 API batches must be persisted as completed.');
    $assert(count(array_unique(array_column($gateway->requests, 'client_import_id'))) === 90, 'Each newly submitted batch must have its own idempotency ID.');
    $assert(array_keys($repository->researchTargetProgress()) === [$target->key()], 'The completed Overture combination must advance the scheduler.');
};

$tests['Overture continues through more than ten productive combinations'] = static function () use ($run, $assert): void {
    $targets = [];
    $counts = [];
    foreach (['Tel Aviv', 'Jerusalem'] as $city) {
        foreach (['food_catering.bakery', 'food_catering.restaurants', 'professionals.fast_food', 'food_catering.cafes', 'professionals.catering', 'professionals.grocery_food'] as $category) {
            $target = new ResearchTarget($city, $category);
            $targets[] = $target;
            $counts[$target->key()] = 5;
        }
    }
    $source = new ImportJobSource('overture_places', $counts);
    [$repository, , $gateway, $report] = $run('worker.overture.json', $targets, $source);

    $assert($report['imported'] === 60 && $report['failed'] === 0, 'All 12 partially filled combinations must import their available rows.');
    $assert($report['productive_target_combinations'] === 12 && $report['scanned_target_combinations'] === 12, 'Overture must not retain the former ten-combination cap.');
    $assert(count($gateway->requests) === 12 && array_sum(array_column($report['targets'], 'successful')) === 60, 'All successes must remain assigned to their separate combinations.');
    $assert($source->visited === array_keys($counts), 'The finite schedule must visit every available combination exactly once.');
    $assert(count($repository->researchTargetProgress()) === 12, 'Every completed combination must retain progress.');
};

$tests['Government skips empty combinations and replaces rejected rows until 100 succeed'] = static function () use ($run, $assert): void {
    $targets = [
        new ResearchTarget('Tel Aviv', 'food_catering.bakery'),
        new ResearchTarget('Tel Aviv', 'food_catering.restaurants'),
        new ResearchTarget('Jerusalem', 'food_catering.cafes'),
        new ResearchTarget('Jerusalem', 'professionals.grocery_food'),
    ];
    $source = new ImportJobSource('data_gov_ckan', [$targets[2]->key() => 180, $targets[3]->key() => 100], true);
    [$repository, , $gateway, $report] = $run('worker.rotation.json', $targets, $source);

    $assert($report['imported'] === 100 && $report['duplicates'] === 10 && $report['failed'] === 5, 'Rejected candidates must not consume the 100-success government budget.');
    $assert(array_map(static fn (array $request): int => count($request['businesses']), $gateway->requests) === [100, 15], 'The government job must refill the 15 unsuccessful slots.');
    $assert($report['found'] === 115 && $gateway->duplicateChecks === 115, 'The government source must be consumed only until 100 successes are reached.');
    $assert($report['empty_target_combinations'] === 2 && $report['productive_target_combinations'] === 1 && $report['scanned_target_combinations'] === 3, 'Empty combinations must not use the one productive slot.');
    $assert($source->visited === array_map(static fn (ResearchTarget $target): string => $target->key(), array_slice($targets, 0, 3)), 'A later productive combination must wait for the next government run.');
    $progress = $repository->researchTargetProgress();
    $assert(count($progress) === 3 && ! isset($progress[$targets[3]->key()]), 'Empty visits must advance progress without marking the untouched combination complete.');
    $assert(($repository->statusSummary()['businesses']['imported'] ?? 0) === 100, 'The actual SQLite imports must match the government success limit.');
};

$tests['Government stops after one productive combination even when fewer than 100 exist'] = static function () use ($run, $assert): void {
    $targets = [
        new ResearchTarget('Tel Aviv', 'food_catering.bakery'),
        new ResearchTarget('Jerusalem', 'food_catering.cafes'),
        new ResearchTarget('Jerusalem', 'professionals.grocery_food'),
    ];
    $source = new ImportJobSource('data_gov_ckan', [$targets[1]->key() => 25, $targets[2]->key() => 100]);
    [$repository, , $gateway, $report] = $run('worker.rotation.json', $targets, $source);

    $assert($report['imported'] === 25 && count($gateway->requests) === 1, 'The government job must retain its one-combination budget when that combination has only 25 rows.');
    $assert($report['productive_target_combinations'] === 1 && $report['empty_target_combinations'] === 1, 'Only the productive government combination must use a slot.');
    $assert($source->visited === [$targets[0]->key(), $targets[1]->key()], 'The next nonempty combination must remain unvisited.');
    $assert(count($repository->researchTargetProgress()) === 2, 'Only actually visited government combinations may advance.');
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
unset($test, $tests, $run);
gc_collect_cycles();
foreach ($directories as $directory) {
    $resolved = realpath($directory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false
        || strcasecmp(dirname($resolved), $temporaryRoot) !== 0
        || ! preg_match('/^sveevee-import-jobs-[a-f0-9]+$/D', basename($resolved))) {
        throw new RuntimeException('Refusing cleanup outside an allocated import-job test directory.');
    }
    foreach (['worker.sqlite-wal', 'worker.sqlite-shm', 'worker.sqlite', 'worker.log'] as $filename) {
        if (is_file($resolved.DIRECTORY_SEPARATOR.$filename)) {
            unlink($resolved.DIRECTORY_SEPARATOR.$filename);
        }
    }
    rmdir($resolved);
}
fwrite(STDOUT, "Import jobs: 4 tests, {$assertions} assertions, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
