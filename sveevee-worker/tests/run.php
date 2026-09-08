<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\OAuthTokenProvider;
use Sveevee\Worker\Api\SveeveeApiClient;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Console\Application;
use Sveevee\Worker\Domain\BusinessCandidate;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Domain\SourceRecord;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\RobotsRules;
use Sveevee\Worker\Research\OverpassSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\Uuid;

final class FakeGateway implements SveeveeGateway
{
    public array $batchRequests = [];
    public bool $failNextBatch = false;

    public function checkDuplicate(array $business): array
    {
        return match ($business['name']) {
            'Existing Business' => ['duplicate' => true, 'matches' => [[
                'id' => 7, 'name' => 'Existing Business', 'matched_on' => ['name'],
            ]]],
            'Claimed Business' => ['duplicate' => true, 'matches' => [[
                'id' => 8, 'name' => 'Claimed Business', 'matched_on' => ['name'],
            ]]],
            default => ['duplicate' => false, 'matches' => []],
        };
    }

    public function searchBusinesses(array $filters): array
    {
        $name = $filters['name'] ?? null;
        if ($name === 'Existing Business') {
            return ['businesses' => [[
                'id' => 7,
                'name' => 'Existing Business',
                'public_description' => null,
                'contact_email' => null,
                'phone' => null,
                'whatsapp' => null,
                'website' => null,
                'address' => ['city' => 'Tel Aviv'],
                'socials' => [],
                'opening_hours' => [],
                'service_areas' => [],
                'specialties' => [],
                'is_unclaimed' => true,
                'can_update' => true,
            ]]];
        }
        if ($name === 'Claimed Business') {
            return ['businesses' => [[
                'id' => 8,
                'name' => 'Claimed Business',
                'address' => ['city' => 'Tel Aviv'],
                'is_unclaimed' => false,
                'can_update' => false,
            ]]];
        }

        return ['businesses' => []];
    }

    public function importBatch(array $request): array
    {
        $this->batchRequests[] = $request;
        if ($this->failNextBatch) {
            $this->failNextBatch = false;
            throw new ApiException('Temporary outage.', 503, 'api_error', retryable: true);
        }

        $items = [];
        foreach ($request['businesses'] as $index => $business) {
            $operation = isset($business['id']) ? 'updated' : 'created';
            $items[] = [
                'position' => $index + 1,
                'status' => $operation,
                'operation' => $operation,
                'business' => ['id' => $business['id'] ?? 1000 + $index],
            ];
        }

        return [
            'client_import_id' => $request['client_import_id'],
            'input_count' => count($items),
            'items' => $items,
            'replayed' => count($this->batchRequests) > 1,
        ];
    }
}

final class QueueHttpClient implements HttpClientInterface
{
    public array $requests = [];

    public function __construct(public array $responses) {}

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): HttpResponse {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'options');
        $response = array_shift($this->responses);
        if (! $response instanceof HttpResponse) {
            throw new RuntimeException('No queued HTTP response.');
        }

        return $response;
    }
}

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[] = [$name, $callback];
};
$assert = static function (bool $condition, string $message = 'Assertion failed.'): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$test('opening hours parser maps OSM weekdays', function () use ($assert): void {
    $rows = (new OpeningHoursParser)->parse('Mo-Th 09:00-17:00; Fr 09:00-13:00; Sa-Su off');
    $byDay = array_column($rows, null, 'weekday');
    $assert($byDay['monday']['is_open'] === true);
    $assert($byDay['monday']['opens_at'] === '09:00');
    $assert($byDay['friday']['closes_at'] === '13:00');
    $assert($byDay['saturday']['is_open'] === false);
});

$test('robots rules use the longest matching rule', function () use ($assert): void {
    $rules = new RobotsRules(<<<'ROBOTS'
User-agent: *
Disallow: /private/
Allow: /private/public/
ROBOTS);
    $assert($rules->allows('SveeveeResearchWorker/1.0', '/index.html'));
    $assert(! $rules->allows('SveeveeResearchWorker/1.0', '/private/item'));
    $assert($rules->allows('SveeveeResearchWorker/1.0', '/private/public/item'));
});

$test('Overpass adapter builds a scoped query and maps a business', function () use ($assert): void {
    $http = new QueueHttpClient([
        new HttpResponse(200, [], json_encode(['elements' => [[
            'type' => 'node',
            'id' => 123,
            'tags' => [
                'name' => 'Test Electrician',
                'craft' => 'electrician',
                'contact:phone' => '03-0000000',
            ],
        ]]], JSON_THROW_ON_ERROR)),
    ]);
    $source = new OverpassSource([
        'endpoint' => 'https://overpass.example/api/interpreter',
        'country_code' => 'IL',
        'timeout_seconds' => 10,
        'min_interval_seconds' => 0,
        'max_queries_per_run' => 1,
        'city_names' => ['Tel Aviv' => ['Tel Aviv-Yafo']],
        'category_tags' => [
            'professionals.electricians' => [['key' => 'craft', 'value' => 'electrician']],
        ],
    ], $http, 'TestWorker/1.0');
    $rows = iterator_to_array($source->research(
        new ResearchTarget('Tel Aviv', 'professionals.electricians'), 1
    ));
    $assert(count($rows) === 1);
    $assert($rows[0]['name'] === 'Test Electrician');
    $assert($rows[0]['source_url'] === 'https://www.openstreetmap.org/node/123');
    parse_str((string) $http->requests[0]['body'], $form);
    $assert(str_contains($form['data'], '["ISO3166-1"="IL"]'));
    $assert(str_contains($form['data'], '["name:en"~'));
    $assert(! str_contains($form['data'], '(?:'));
    $assert(! str_contains($form['data'], '\\-'));
    $assert(str_contains($form['data'], 'out tags center qt'));
});

$test('normalizer keeps sources local and creates stable identity keys', function () use ($assert): void {
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv', 'Jerusalem']);
    $candidate = $normalizer->normalize([
        'name' => '  Example Business ',
        'contact_email' => 'INFO@EXAMPLE.COM',
        'phone' => '03-0000000',
        'category_key' => 'professionals.electricians',
        'address' => ['city' => 'tel aviv'],
        'source_name' => 'fixture',
        'source_url' => 'https://example.com/source/1',
    ], new ResearchTarget('Tel Aviv', 'professionals.electricians'), 'fixture');

    $assert($candidate->data['name'] === 'Example Business');
    $assert($candidate->data['contact_email'] === 'info@example.com');
    $assert(! array_key_exists('source_url', $candidate->data));
    $keys = $normalizer->identityKeys($candidate->data);
    $assert($keys['phone'] === '97230000000');
    $assert($keys['email'] === 'info@example.com');
});

$test('OAuth token is cached and renewed after an API 401', function () use ($assert): void {
    $http = new QueueHttpClient([
        new HttpResponse(200, [], '{"access_token":"token-one","expires_in":3600}'),
        new HttpResponse(401, [], '{"success":false,"message":"Unauthenticated."}'),
        new HttpResponse(200, [], '{"access_token":"token-two","expires_in":3600}'),
        new HttpResponse(200, [], '{"success":true,"data":{"duplicate":false,"matches":[]}}'),
    ]);
    $tokens = new OAuthTokenProvider(
        $http, 'https://sveevee.test/oauth/token', 'client-id', 'client-secret', 5, 'TestWorker/1.0'
    );
    $api = new SveeveeApiClient(
        $http, $tokens, 'https://sveevee.test/api/v1/business-import', 5, 0, 1, 'TestWorker/1.0'
    );
    $result = $api->checkDuplicate(['name' => 'Test']);
    $assert($result['duplicate'] === false);
    $assert(count($http->requests) === 4);
    $assert($http->requests[1]['headers']['Authorization'] === 'Bearer token-one');
    $assert($http->requests[3]['headers']['Authorization'] === 'Bearer token-two');
});

$test('remote patches fill gaps without deleting existing values', function () use ($assert): void {
    $patch = (new BusinessMerger)->patchForRemote([
        'public_description' => 'Verified description',
        'phone' => '03-0000000',
        'address' => ['city' => 'Tel Aviv', 'street' => 'New Street'],
        'specialties' => ['Repairs', 'Lighting'],
    ], [
        'id' => 77,
        'public_description' => null,
        'phone' => '03-1111111',
        'address' => ['city' => 'Tel Aviv', 'street' => null],
        'specialties' => ['Repairs'],
    ]);
    $assert($patch['id'] === 77);
    $assert($patch['public_description'] === 'Verified description');
    $assert(! isset($patch['phone']), 'Existing phone must not be overwritten.');
    $assert($patch['address']['street'] === 'New Street');
    $assert($patch['specialties'] === ['Repairs', 'Lighting']);
});

$test('repository deduplicates locally and import handles create update and claimed', function () use ($assert): void {
    [$repository, $logger, $directory] = testRepository();
    foreach (['New Business', 'Existing Business', 'Claimed Business'] as $name) {
        $repository->upsertCandidate(testCandidate($name));
    }
    $again = $repository->upsertCandidate(testCandidate('New Business'));
    $assert($again['is_new'] === false);

    $gateway = new FakeGateway;
    $report = new RunReport(Uuid::v4(), 'import', false);
    $repository->startRun($report->runId, 'import', false, 'test');
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100, null))
        ->import($report->runId, 10, false, $report);
    $status = $repository->statusSummary()['businesses'];
    $assert(($status['imported'] ?? 0) === 1);
    $assert(($status['updated'] ?? 0) === 1);
    $assert(($status['claimed'] ?? 0) === 1);
    $assert(count($gateway->batchRequests) === 1);
    $assert(count($gateway->batchRequests[0]['businesses']) === 2);
    $assert(count($gateway->batchRequests[0]['businesses']) <= 100);
});

$test('retry reuses the persisted batch UUID', function () use ($assert): void {
    [$repository, $logger, $directory] = testRepository();
    $repository->upsertCandidate(testCandidate('Retry Business'));
    $firstGateway = new FakeGateway;
    $firstGateway->failNextBatch = true;
    $firstReport = new RunReport(Uuid::v4(), 'import', false);
    $repository->startRun($firstReport->runId, 'import', false, 'test');
    (new ImportService($firstGateway, $repository, new BusinessMerger, $logger, 100, null))
        ->import($firstReport->runId, 10, false, $firstReport);
    $firstId = $firstGateway->batchRequests[0]['client_import_id'];

    $secondGateway = new FakeGateway;
    $secondReport = new RunReport(Uuid::v4(), 'import', false);
    $repository->startRun($secondReport->runId, 'import', false, 'test');
    (new ImportService($secondGateway, $repository, new BusinessMerger, $logger, 100, null))
        ->import($secondReport->runId, 10, false, $secondReport);
    $assert($secondGateway->batchRequests[0]['client_import_id'] === $firstId);
    $assert(($repository->statusSummary()['businesses']['imported'] ?? 0) === 1);
});

$test('dry run never calls the write endpoint', function () use ($assert): void {
    [$repository, $logger, $directory] = testRepository();
    $repository->upsertCandidate(testCandidate('Dry Run Business'));
    $gateway = new FakeGateway;
    $report = new RunReport(Uuid::v4(), 'run', true);
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100, null))
        ->import($report->runId, 10, true, $report);
    $assert($gateway->batchRequests === []);
    $assert(($repository->statusSummary()['businesses']['pending'] ?? 0) === 1);
});

$test('worker splits large work blocks into batches of at most 100', function () use ($assert): void {
    [$repository, $logger, $directory] = testRepository();
    foreach (range(1, 205) as $number) {
        $repository->upsertCandidate(testCandidate(sprintf('Batch Business %03d', $number)));
    }
    $gateway = new FakeGateway;
    $report = new RunReport(Uuid::v4(), 'import', false);
    $repository->startRun($report->runId, 'import', false, 'test');
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100, null))
        ->import($report->runId, 205, false, $report);
    $sizes = array_map(static fn (array $request): int => count($request['businesses']), $gateway->batchRequests);
    $assert($sizes === [100, 100, 5], 'Expected batches of 100, 100, and 5.');

    $secondReport = new RunReport(Uuid::v4(), 'import', false);
    $repository->startRun($secondReport->runId, 'import', false, 'test');
    (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100, null))
        ->import($secondReport->runId, 205, false, $secondReport);
    $assert(count($gateway->batchRequests) === 3, 'Successful businesses must not be imported again.');
});

$test('research CLI persists seed data and writes a JSON report', function () use ($assert): void {
    global $tempDirectories;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sveevee-worker-cli-'.bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $tempDirectories[] = $directory;
    $seed = $directory.DIRECTORY_SEPARATOR.'seeds.json';
    $config = $directory.DIRECTORY_SEPARATOR.'worker.json';
    file_put_contents($seed, json_encode([[
        'name' => 'CLI Business',
        'category_key' => 'professionals.electricians',
        'address' => ['city' => 'Tel Aviv'],
        'source_name' => 'cli_fixture',
        'source_url' => 'https://example.com/cli-business',
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($config, json_encode([
        'target_per_run' => 10,
        'batch_size' => 100,
        'cities' => ['Tel Aviv'],
        'neighborhoods' => [],
        'categories' => ['professionals.electricians'],
        'sources' => [
            'json_seed' => ['enabled' => true, 'paths' => [$seed], 'refresh_after_days' => 30],
            'overpass' => ['enabled' => false],
            'official_website' => ['enabled' => false],
        ],
        'storage' => [
            'database' => $directory.DIRECTORY_SEPARATOR.'worker.sqlite',
            'reports_dir' => $directory.DIRECTORY_SEPARATOR.'reports',
            'log_file' => $directory.DIRECTORY_SEPARATOR.'worker.log',
        ],
    ], JSON_THROW_ON_ERROR));

    $exit = (new Application(dirname(__DIR__)))->run([
        'worker', 'research', '--config='.$config, '--limit=10',
    ]);
    $assert($exit === 0);
    $database = new PDO('sqlite:'.$directory.DIRECTORY_SEPARATOR.'worker.sqlite');
    $assert((int) $database->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 1);
    $reports = glob($directory.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'*.json') ?: [];
    $assert(count($reports) === 1);
    $report = json_decode((string) file_get_contents($reports[0]), true, 512, JSON_THROW_ON_ERROR);
    $assert($report['found'] === 1 && $report['new'] === 1);
    unset($database);
});

function testRepository(): array
{
    global $tempDirectories;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sveevee-worker-test-'.bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $tempDirectories[] = $directory;
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv', 'Jerusalem']);
    $repository = new WorkerRepository(
        new Database($directory.DIRECTORY_SEPARATOR.'worker.sqlite'),
        $normalizer,
        new BusinessMerger,
    );

    return [$repository, new Logger($directory.DIRECTORY_SEPARATOR.'worker.log'), $directory];
}

function testCandidate(string $name): BusinessCandidate
{
    return new BusinessCandidate([
        'type' => 'business',
        'name' => $name,
        'public_description' => 'Verified description for '.$name,
        'category_key' => 'professionals.electricians',
        'address' => ['city' => 'Tel Aviv'],
    ], [new SourceRecord('test', 'test', 'https://example.com/'.rawurlencode($name), Clock::now(), ['name' => $name])]);
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$tempDirectories = [];
$failures = 0;
foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
gc_collect_cycles();
foreach ($tempDirectories as $directory) {
    removeTree($directory);
}
exit($failures === 0 ? 0 : 1);
