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
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

final class ImportLocationGateway implements SveeveeGateway
{
    public array $duplicateRequests = [];

    public array $searchRequests = [];

    public array $batchRequests = [];

    public ?Closure $searchHandler = null;

    public function __construct(public array $matches = [], public array $remote = []) {}

    public function checkDuplicate(array $business): array
    {
        $this->duplicateRequests[] = $business;

        return ['matches' => $this->matches];
    }

    public function searchBusinesses(array $filters): array
    {
        $this->searchRequests[] = $filters;
        if ($this->searchHandler !== null) {
            return ($this->searchHandler)($filters);
        }
        $rows = array_values(array_filter($this->remote, static function (array $row) use ($filters): bool {
            foreach (['id', 'name', 'phone', 'contact_email'] as $key) {
                if (isset($filters[$key]) && (string) ($row[$key] ?? '') !== (string) $filters[$key]) {
                    return false;
                }
            }

            return ! isset($filters['city']) || ($row['address']['city'] ?? '') === $filters['city'];
        }));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 100));

        return [
            'businesses' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'pagination' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / $perPage))],
        ];
    }

    public function importBatch(array $request): array
    {
        $this->batchRequests[] = $request;

        return ['items' => array_map(static fn (array $row, int $index): array => [
            'position' => $index + 1,
            'status' => isset($row['id']) ? 'updated' : 'created',
            'business' => ['id' => $row['id'] ?? 10000 + $index],
        ], $request['businesses'], array_keys($request['businesses']))];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

$directories = [];
$context = static function () use (&$directories): array {
    $directory = sys_get_temp_dir().'/sveevee-import-location-'.bin2hex(random_bytes(8));
    $database = new Database($directory.'/worker.sqlite');
    $directories[] = $directory;
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv', 'Jerusalem']);

    return [new WorkerRepository($database, $normalizer, new BusinessMerger), $normalizer, new Logger($directory.'/worker.log', false)];
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$record = static fn (array $overrides = []): array => array_replace([
    'name' => 'Cafe Chain', 'category_key' => 'food_catering.cafes',
    'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street 10'],
    'phone' => '+97235550100', 'website' => 'https://chain.example.org/',
    'source_url' => 'https://explore.overturemaps.org/?feature=places.place.'.bin2hex(random_bytes(6)),
], $overrides);
$target = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
$run = static function (WorkerRepository $repository, Logger $logger, ImportLocationGateway $gateway, bool $dryRun = false): RunReport {
    $report = new RunReport(Uuid::v4(), 'import', $dryRun);
    $repository->startRun($report->runId, 'import', $dryRun, 'location-fixture');
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100))->import($report->runId, 100, $dryRun, $report);

    return $report;
};
$summary = static fn (array $row, array $signals): array => [
    'id' => $row['id'], 'name' => $row['name'], 'address' => $row['address'], 'matched_on' => $signals,
];
$tests = [];
$tests['a confirmed location outranks a different branch with stronger shared contact signals'] = static function () use ($context, $record, $target, $run, $summary, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    $repository->upsertCandidate($normalizer->normalize($record(['contact_email' => 'new@example.org']), $target, 'overture_places'));
    $wrong = $record(['id' => 1, 'can_update' => true, 'address' => ['city' => 'Jerusalem', 'street' => 'Example Street 10']]);
    $right = $record(['id' => 2, 'can_update' => true]);
    $gateway = new ImportLocationGateway([
        $summary($wrong, ['contact_email', 'phone', 'website']),
        $summary($right, ['name', 'address']),
    ], [$wrong, $right]);
    $report = $run($repository, $logger, $gateway);
    $assert($gateway->batchRequests[0]['businesses'] === [['id' => 2, 'contact_email' => 'new@example.org']], 'A stronger contact signal selected another location or replaced existing data.');
    $assert($report->metric('updated') === 1 && $report->metric('imported') === 0, 'Confirmed location was not updated.');
};
$tests['remote search follows pagination to a matching branch beyond the first hundred records'] = static function () use ($context, $record, $target, $run, $summary, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    $incoming = $record(['address' => ['city' => 'Tel Aviv', 'street' => 'Example Street 101'], 'contact_email' => 'new@example.org']);
    $repository->upsertCandidate($normalizer->normalize($incoming, $target, 'overture_places'));
    $remote = [];
    for ($number = 1; $number <= 101; $number++) {
        $remote[] = $record(['id' => $number, 'can_update' => true, 'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street '.$number]]);
    }
    $gateway = new ImportLocationGateway([$summary($remote[100], ['name', 'address'])], $remote);
    $report = $run($repository, $logger, $gateway);
    $assert(array_column($gateway->searchRequests, 'page') === [1, 2], 'Search stopped before the matching second page.');
    $assert($gateway->batchRequests[0]['businesses'][0]['id'] === 101 && $report->metric('updated') === 1, 'Wrong branch was updated after pagination.');
};
$tests['client verifies the fetched page location again before patching'] = static function () use ($context, $record, $target, $run, $summary, $assert): void {
    foreach ([false, true] as $dryRun) {
        [$repository, $normalizer, $logger] = $context();
        $stored = $repository->upsertCandidate($normalizer->normalize($record(['contact_email' => 'new@example.org']), $target, 'overture_places'));
        $match = $record(['id' => 10, 'can_update' => true]);
        $actual = array_replace($match, ['address' => ['city' => 'Tel Aviv', 'street' => 'Different Street 99']]);
        $gateway = new ImportLocationGateway([$summary($match, ['name', 'address'])], [$actual]);
        $report = $run($repository, $logger, $gateway, $dryRun);
        $assert($gateway->batchRequests === [] && $report->metric('planned_updates') === 0, 'Changed remote location received a patch.');
        $assert($report->metric('failed') === 1, 'Unresolved remote location was hidden.');
        $status = $repository->business($stored['business_id']);
        $assert($dryRun ? $status['status'] === 'pending' : $status['last_error_code'] === 'duplicate_unresolved', 'Conflict status did not respect dry-run semantics.');
    }
};
$tests['backend matches-empty decisions import distinct chain locations independently'] = static function () use ($context, $record, $target, $run, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    foreach (['10', '11'] as $number) {
        $repository->upsertCandidate($normalizer->normalize($record([
            'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street '.$number],
        ]), $target, 'overture_places'));
    }
    $gateway = new ImportLocationGateway([], [$record(['id' => 99, 'address' => ['city' => 'Jerusalem', 'street' => 'Elsewhere 1']])]);
    $report = $run($repository, $logger, $gateway);
    $rows = $gateway->batchRequests[0]['businesses'];
    $assert(count($rows) === 2 && ! isset($rows[0]['id']) && ! isset($rows[1]['id']), 'New branches were redirected to an existing contact match.');
    $assert($rows[0]['address'] !== $rows[1]['address'] && $report->metric('imported') === 2, 'Distinct locations were collapsed.');
    $assert($gateway->searchRequests === [], 'Empty branch duplicate results caused an unrelated page lookup.');
};
$tests['equally strong duplicate pages for the same location remain unresolved'] = static function () use ($context, $record, $target, $run, $summary, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    $repository->upsertCandidate($normalizer->normalize($record(['contact_email' => 'new@example.org']), $target, 'overture_places'));
    $first = $record(['id' => 1, 'can_update' => true]);
    $second = $record(['id' => 2, 'can_update' => true]);
    $gateway = new ImportLocationGateway([$summary($first, ['name', 'address']), $summary($second, ['name', 'address'])], [$first, $second]);
    $report = $run($repository, $logger, $gateway);
    $assert($gateway->batchRequests === [] && $report->metric('failed') === 1, 'Ambiguous identical locations were silently resolved.');
};
$tests['broken pagination stops without creating or updating a replacement business'] = static function () use ($context, $record, $target, $run, $summary, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    $repository->upsertCandidate($normalizer->normalize($record(['contact_email' => 'new@example.org']), $target, 'overture_places'));
    $selected = $record(['id' => 1000, 'can_update' => true]);
    $gateway = new ImportLocationGateway([$summary($selected, ['name', 'address'])]);
    $gateway->searchHandler = static fn (array $filters): array => [
        'businesses' => [['id' => 1]], 'pagination' => ['current_page' => 1, 'last_page' => 1000],
    ];
    $report = $run($repository, $logger, $gateway);
    $assert(count($gateway->searchRequests) === 2, 'Repeated page metadata did not stop pagination.');
    $assert($gateway->batchRequests === [] && $report->metric('failed') === 1, 'Broken pagination caused a replacement write.');
};
$tests['a stable GERS link handles a corrected source name through the known remote page ID'] = static function () use ($context, $record, $target, $run, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    $original = $record();
    $stored = $repository->upsertCandidate($normalizer->normalize($original, $target, 'overture_places'));
    $repository->markBusiness($stored['business_id'], 'imported', 123, operation: 'created');
    $corrected = array_replace($original, ['name' => 'Corrected Chain Name', 'contact_email' => 'new@example.org']);
    $again = $repository->upsertCandidate($normalizer->normalize($corrected, $target, 'overture_places'));
    $assert($again['business_id'] === $stored['business_id'], 'Local GERS correction lost its identity.');
    $gateway = new ImportLocationGateway([], [array_replace($original, ['id' => 123, 'can_update' => true])]);
    $report = $run($repository, $logger, $gateway);
    $assert($gateway->duplicateRequests === [], 'Known GERS mapping was replaced by a name-based duplicate decision.');
    $assert($gateway->searchRequests === [['id' => 123, 'per_page' => 1]], 'Known page was not resolved by its exact ID.');
    $assert($gateway->batchRequests[0]['businesses'] === [['id' => 123, 'contact_email' => 'new@example.org']], 'Source correction renamed the public page or caused a duplicate create.');
    $assert($report->metric('updated') === 1 && $report->metric('imported') === 0, 'Corrected source created a replacement page.');
};
$tests['missing moved incomplete or claimed linked pages never create a replacement'] = static function () use ($context, $record, $target, $run, $assert): void {
    foreach (['missing', 'wrong_id', 'moved', 'incomplete', 'claimed', 'api_error'] as $mode) {
        [$repository, $normalizer, $logger] = $context();
        $original = $record(['contact_email' => 'new@example.org']);
        $stored = $repository->upsertCandidate($normalizer->normalize($original, $target, 'overture_places'));
        $repository->markBusiness($stored['business_id'], 'pending', 123);
        $remote = $record(['id' => 123, 'can_update' => $mode !== 'claimed']);
        if ($mode === 'moved') {
            $remote['address']['street'] = 'Other Street 50';
        } elseif ($mode === 'incomplete') {
            unset($remote['address']['street']);
        } elseif ($mode === 'wrong_id') {
            $remote['id'] = 456;
        }
        $gateway = new ImportLocationGateway([], $mode === 'missing' ? [] : [$remote]);
        if ($mode === 'api_error') {
            $gateway->searchHandler = static fn (array $filters): array => throw new ApiException('Lookup temporarily unavailable.', 503, 'api_error', retryable: true);
        }
        $report = $run($repository, $logger, $gateway);
        $assert($gateway->batchRequests === [] && $gateway->duplicateRequests === [], $mode.' link fell back to creating or replacing a page.');
        $assert($report->metric('planned_imports') === 0 && $report->metric('planned_updates') === 0, $mode.' link planned an unsafe write.');
        $status = $repository->business($stored['business_id'])['status'];
        $assert($status === ($mode === 'claimed' ? 'claimed' : 'failed'), 'Unexpected linked-page status for '.$mode);
    }
};
$tests['stored remote IDs from other source types do not bypass branch duplicate checks'] = static function () use ($context, $record, $target, $run, $assert): void {
    [$repository, $normalizer, $logger] = $context();
    $stored = $repository->upsertCandidate($normalizer->normalize($record(), $target, 'data_gov_ckan'));
    $repository->markBusiness($stored['business_id'], 'pending', 123);
    $gateway = new ImportLocationGateway;
    $report = $run($repository, $logger, $gateway);
    $assert(count($gateway->duplicateRequests) === 1 && $gateway->searchRequests === [], 'An unrelated source trusted a stored ID without branch duplicate checks.');
    $assert(! isset($gateway->batchRequests[0]['businesses'][0]['id']) && $report->metric('imported') === 1, 'An unrelated source forced a remote-ID update.');
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
unset($tests, $test);
gc_collect_cycles();
foreach ($directories as $directory) {
    foreach (['worker.sqlite-wal', 'worker.sqlite-shm', 'worker.sqlite', 'worker.log'] as $filename) {
        if (is_file($directory.'/'.$filename)) {
            @unlink($directory.'/'.$filename);
        }
    }
    @rmdir($directory);
}
fwrite(STDOUT, "{$count} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
