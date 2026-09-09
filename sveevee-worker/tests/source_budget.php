<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\BudgetedHttpClient;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Http\SourceRequestBudgetExceeded;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\DataGovCkanSource;
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Research\TelAvivBusinessLicenseSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

final class BudgetFixtureHttp implements HttpClientInterface
{
    public int $calls = 0;

    public function __construct(private readonly int $status = 200) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $this->calls++;

        return new HttpResponse($this->status, [], '{}');
    }
}

final class BudgetFixtureSource implements SourceAdapterInterface
{
    public int $visits = 0;

    public function __construct(private readonly string $adapter, private readonly Closure $fetch) {}

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
        $this->visits++;

        return ($this->fetch)($target);
    }
}

final class BudgetFixtureGateway implements SveeveeGateway
{
    public array $batches = [];

    public function checkDuplicate(array $business): array
    {
        return ['matches' => []];
    }

    public function searchBusinesses(array $filters): array
    {
        throw new RuntimeException('Unexpected remote search.');
    }

    public function reportRun(array $report): array
    {
        return [];
    }

    public function importBatch(array $request): array
    {
        $this->batches[] = $request;

        return ['items' => array_map(static fn (int $index): array => [
            'position' => $index + 1, 'status' => 'created', 'business' => ['id' => 1000 + $index],
        ], array_keys($request['businesses']))];
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$directory = dirname(__DIR__).'/var/source-budget-tests-'.bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($directory): void {
    if (is_file($directory.'/worker.log')) {
        unlink($directory.'/worker.log');
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
});
$fixture = static function (array $targets, ?array $sourceScope = null, bool $dryRun = false) use ($directory): array {
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, array_unique(array_map(
        static fn (ResearchTarget $target): string => $target->city, $targets,
    )));
    $repository = new WorkerRepository(new Database(':memory:'), $normalizer, new BusinessMerger, $sourceScope);
    $report = new RunReport(Uuid::v4(), 'run', $dryRun);
    $report->enableSourceMetrics();
    foreach ($sourceScope ?? [] as $source) {
        $report->source($source, 0);
    }
    $repository->startRun($report->runId, 'run', false, 'fixture');

    return [$repository, $normalizer, $report, new Logger($directory.'/worker.log', false)];
};
$run = static function (array $targets, array $sources, array $fixture): array {
    [$repository, $normalizer, $report, $logger] = $fixture;
    $research = new ResearchService($sources, [], $targets, $normalizer, $repository, $logger, 10, 1);
    $gateway = new BudgetFixtureGateway;
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 10))->runTargets(
        $report->runId, $targets, 10, 1, 10, $report->dryRun, $report, $research,
    );

    return [$report->toArray(), $gateway, $research];
};
$row = static fn (ResearchTarget $target, int $id): array => [
    'name' => 'Budget test business '.$id,
    'category_key' => $target->categoryKey,
    'address' => ['city' => $target->city, 'street' => 'Fixture Street', 'number' => (string) $id],
    'source_url' => 'https://example.org/budget/'.$id,
];

$tests = [];
$tests['830 targets with unavailable real adapters make only two upstream requests'] = static function () use ($assert, $fixture, $run): void {
    $settings = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/worker.rotation.json'));
    $targets = [];
    foreach ($settings['cities'] as $city) {
        foreach ($settings['categories'] as $category) {
            $targets[] = new ResearchTarget($city, is_array($category) ? $category['key'] : $category);
        }
    }
    $context = $fixture($targets);
    [$repository, , $report] = $context;
    $inner = new BudgetFixtureHttp(404);
    $http = new BudgetedHttpClient($inner, 10, static fn () => $report->increment('source_requests'));
    $gov = new DataGovCkanSource(array_replace($settings['sources']['data_gov_ckan'], ['min_interval_seconds' => 0]), $http, $repository, 'Test');
    $telSettings = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/worker.tel-aviv.json'));
    $tel = new TelAvivBusinessLicenseSource(array_replace($telSettings['sources']['tel_aviv_business_licenses'], ['min_interval_seconds' => 0]), $http, $repository, 'Test');
    [$result] = $run($targets, [$gov, $tel], $context);
    $assert(count($targets) === 830, 'Fixture must reproduce the full 83 x 10 schedule.');
    $assert($inner->calls === 2 && $result['source_requests'] === 2, 'Both failing adapters must each make exactly one upstream request.');
    $assert($result['failed'] === 2 && $result['source_errors'] === 2, 'An outage must record two source errors, not 840 failed businesses.');
    $assert($result['empty_target_combinations'] === 0, 'Outages must not be counted as valid empty results.');
    $assert($repository->researchTargetProgress() === [], 'Unavailable targets must not advance the scheduler.');
    $assert($result['scanned_target_combinations'] < 830, 'Stop visiting the remaining schedule once both sources have paused.');
};
$tests['ten-request budget preserves valid empties and defers the unfinished target'] = static function () use ($assert, $fixture, $run): void {
    $targets = array_map(static fn (int $id): ResearchTarget => new ResearchTarget('City '.$id, 'food_catering.cafes'), range(1, 20));
    $context = $fixture($targets);
    [$repository, , $report] = $context;
    $inner = new BudgetFixtureHttp;
    $http = new BudgetedHttpClient($inner, 10, static fn () => $report->increment('source_requests'));
    $source = new BudgetFixtureSource('data_gov_ckan', static function () use ($http): array {
        $http->request('GET', 'https://example.org/empty');

        return [];
    });
    [$result] = $run($targets, [$source], $context);
    $assert($inner->calls === 10 && $result['source_requests'] === 10, 'The eleventh request must not reach the network.');
    $assert($result['failed'] === 0 && $result['source_errors'] === 0, 'An intentional request ceiling is not a source failure.');
    $assert($result['empty_target_combinations'] === 10 && $result['deferred_target_combinations'] === 1, 'Valid empties and unfinished research must remain distinct.');
    $assert(count($repository->researchTargetProgress()) === 10 && ! isset($repository->researchTargetProgress()[$targets[10]->key()]), 'Only the ten completed empty targets may advance.');
};
$tests['one failed source stays paused while the other source continues'] = static function () use ($assert, $fixture, $run, $row): void {
    $targets = [new ResearchTarget('Tel Aviv', 'food_catering.bakery'), new ResearchTarget('Tel Aviv', 'food_catering.cafes')];
    $context = $fixture($targets);
    $broken = new BudgetFixtureSource('data_gov_ckan', static function (): array {
        throw new RuntimeException('HTTP 404');
    });
    $healthy = new BudgetFixtureSource('tel_aviv_business_licenses', static fn (ResearchTarget $target): array => $target->categoryKey === 'food_catering.cafes' ? [$row($target, 1)] : []);
    [$result, $gateway] = $run($targets, [$broken, $healthy], $context);
    $assert($broken->visits === 1 && $healthy->visits === 2, 'Only the failed adapter must pause.');
    $assert($result['imported'] === 1 && $result['source_errors'] === 1 && count($gateway->batches) === 1, 'The healthy source must still be able to import.');
    $assert($result['empty_target_combinations'] === 0 && $result['deferred_target_combinations'] === 2, 'Targets partially missing a source must remain deferred.');
};
$tests['request exhaustion flushes already prepared rows without marking research complete'] = static function () use ($assert, $fixture, $run, $row): void {
    $target = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
    $context = $fixture([$target]);
    [$repository, , $report] = $context;
    $inner = new BudgetFixtureHttp;
    $http = new BudgetedHttpClient($inner, 10, static fn () => $report->increment('source_requests'));
    $source = new BudgetFixtureSource('data_gov_ckan', static function (ResearchTarget $target) use ($http, $row): iterable {
        for ($id = 1; $id <= 11; $id++) {
            $http->request('GET', 'https://example.org/page');
            if ($id <= 3) {
                yield $row($target, $id);
            }
        }
    });
    [$result, $gateway] = $run([$target], [$source], $context);
    $assert($result['imported'] === 3 && count($gateway->batches[0]['businesses']) === 3, 'Prepared rows must not be lost when the source request budget runs out.');
    $assert($inner->calls === 10 && $result['deferred_target_combinations'] === 1 && $repository->researchTargetProgress() === [], 'Partial results must retain the unfinished research target.');
};
$tests['shared wrapper counts failed attempts and forwards no eleventh retry'] = static function () use ($assert): void {
    $inner = new BudgetFixtureHttp(503);
    $http = new BudgetedHttpClient($inner, 10);
    for ($id = 0; $id < 10; $id++) {
        $http->request('GET', 'https://example.org/'.($id % 2 === 0 ? 'gov' : 'tel'));
    }
    try {
        $http->request('GET', 'https://example.org/retry');
        throw new RuntimeException('Expected SourceRequestBudgetExceeded.');
    } catch (SourceRequestBudgetExceeded $exception) {
        $assert($exception->limit === 10 && $inner->calls === 10 && $http->requestCount() === 10, 'Failures and both hosts must share the same ceiling.');
    }
};
$tests['local pending businesses remain importable after both sources pause'] = static function () use ($assert, $fixture, $run, $row): void {
    $targets = [new ResearchTarget('Tel Aviv', 'food_catering.bakery'), new ResearchTarget('Tel Aviv', 'food_catering.cafes')];
    $context = $fixture($targets);
    [$repository, $normalizer] = $context;
    for ($id = 1; $id <= 10; $id++) {
        $repository->upsertCandidate($normalizer->normalize($row($targets[1], $id), $targets[1], 'data_gov_ckan'));
    }
    $fail = static function (): array {
        throw new RuntimeException('HTTP 404');
    };
    $gov = new BudgetFixtureSource('data_gov_ckan', $fail);
    $tel = new BudgetFixtureSource('tel_aviv_business_licenses', $fail);
    [$result, $gateway] = $run($targets, [$gov, $tel], $context);
    $assert($result['imported'] === 10 && count($gateway->batches) === 1, 'A source outage must not block existing local candidates in a later target.');
    $assert($gov->visits === 1 && $tel->visits === 1, 'Importing local candidates must not retry the paused sources.');
    $assert($result['deferred_target_combinations'] === 2 && $repository->researchTargetProgress() === [], 'Reaching the write cap with local rows must not falsely complete unavailable research.');
};
$tests['Overture report fields stay unchanged without government metrics opt-in'] = static function () use ($assert): void {
    $report = new RunReport(Uuid::v4(), 'run', false);
    $report->source('overture_places', 10);
    $report->target('Tel Aviv|food_catering.cafes', 'Tel Aviv', 'food_catering.cafes', 10, 10);
    $result = $report->toArray();
    $assert(array_intersect_key($result, array_flip(['source_requests', 'source_errors', 'deferred_target_combinations'])) === [] && ! array_key_exists('deferred', $result['targets'][0]), 'Overture must retain its original report fields.');
};

$tests['separate government and Tel Aviv runs retain their own failure counters and source labels'] = static function () use ($assert, $fixture, $run): void {
    $reports = [];
    foreach (['data_gov_ckan' => 'worker.rotation.json', 'tel_aviv_business_licenses' => 'worker.tel-aviv.json'] as $adapter => $profile) {
        $settings = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/'.$profile));
        $targets = [];
        foreach ($settings['cities'] as $city) {
            foreach ($settings['categories'] as $category) {
                $targets[] = new ResearchTarget($city, $category);
            }
        }
        $context = $fixture($targets, [$adapter]);
        [$repository, , $report] = $context;
        $inner = new BudgetFixtureHttp(404);
        $http = new BudgetedHttpClient($inner, 10, static fn () => $report->increment('source_requests'));
        $sourceSettings = array_replace($settings['sources'][$adapter], ['min_interval_seconds' => 0]);
        $source = $adapter === 'data_gov_ckan'
            ? new DataGovCkanSource($sourceSettings, $http, $repository, 'Test')
            : new TelAvivBusinessLicenseSource($sourceSettings, $http, $repository, 'Test');
        [$result] = $run($targets, [$source], $context);
        $assert($result['used_sources'] === [$adapter] && $result['source_counts'] === [$adapter => 0], 'A separate job must not claim the other source in its report.');
        $assert($inner->calls === 1 && $result['source_errors'] === 1 && $result['failed'] === 1, 'One failed source must cause exactly one request and one error in its own run.');
        $assert($result['scanned_target_combinations'] === 1 && $result['empty_target_combinations'] === 0, 'A failed job must stop before iterating through other city/categories.');
        $reports[] = $result;
    }
    $assert($reports[0]['run_id'] !== $reports[1]['run_id'], 'Government and Tel Aviv need independent report IDs.');
};

$tests['source-scoped pending imports and retry-failed retain the other source history'] = static function () use ($assert, $fixture, $run, $row): void {
    $target = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
    foreach ([false, true] as $dryRun) {
        $context = $fixture([$target], ['data_gov_ckan'], $dryRun);
        [$repository, $normalizer] = $context;
        $gov = $repository->upsertCandidate($normalizer->normalize($row($target, 1), $target, 'data_gov_ckan'))['business_id'];
        $tel = $repository->upsertCandidate($normalizer->normalize($row($target, 2), $target, 'tel_aviv_business_licenses'))['business_id'];
        $assert(array_column(iterator_to_array($repository->pendingForTarget($target)), 'id') === [$gov], 'The Gov queue must exclude legacy Tel-only candidates.');
        [$result, $gateway] = $run([$target], [], $context);
        $assert(($dryRun ? $result['planned_imports'] : $result['imported']) === 1, 'Exactly the Gov candidate may consume the run budget.');
        $assert($repository->business($tel)['status'] === 'pending', 'A Gov import must preserve the Tel candidate status.');
        $assert($dryRun ? $gateway->batches === [] : $gateway->batches[0]['businesses'][0]['name'] === 'Budget test business 1', 'The API must never receive the Tel candidate from the Gov job.');
        $repository->markBusiness($gov, 'failed');
        $repository->markBusiness($tel, 'failed');
        $assert($repository->resetFailed(10) === 1 && $repository->business($gov)['status'] === 'pending' && $repository->business($tel)['status'] === 'failed', 'retry-failed must only requeue candidates belonging to this source job.');
    }
};

$tests['foreign and mixed legacy batches remain unchanged without blocking new Gov imports'] = static function () use ($assert, $fixture, $run, $row): void {
    $target = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
    foreach ([false, true] as $mixed) {
        $context = $fixture([$target], ['data_gov_ckan']);
        [$repository, $normalizer, $report] = $context;
        $oldItems = [];
        for ($id = 1; $id <= 11; $id++) {
            $source = $mixed && $id === 11 ? 'data_gov_ckan' : 'tel_aviv_business_licenses';
            $businessId = $repository->upsertCandidate($normalizer->normalize($row($target, $id), $target, $source))['business_id'];
            $oldItems[] = ['business_id' => $businessId, 'payload' => $repository->business($businessId)['payload']];
        }
        $oldBatch = $repository->createBatch($report->runId, $oldItems);
        foreach ([20, 21] as $id) {
            $repository->upsertCandidate($normalizer->normalize($row($target, $id), $target, 'data_gov_ckan'));
        }
        [$result, $gateway] = $run([$target], [], $context);
        $assert($result['imported'] === 2 && count($gateway->batches) === 1, 'A foreign oversized batch must not stop imports belonging to Gov.');
        $pending = $repository->pendingBatches();
        $assert(count($pending) === 1 && $pending[0]['client_import_id'] === $oldBatch['client_import_id'] && $pending[0]['request'] === $oldBatch['request'], 'A legacy mixed/foreign batch must retain its exact request and idempotency ID.');
        $assert((int) $pending[0]['attempts'] === 0 && $result['errors'][0]['stage'] === 'source_scope_deferred', 'Foreign retries must be deferred explicitly without reaching the API.');
    }
};

foreach ($tests as $name => $test) {
    $test();
    fwrite(STDOUT, '[PASS] '.$name.PHP_EOL);
}
fwrite(STDOUT, count($tests).' tests, '.$assertions.' assertions passed.'.PHP_EOL);
