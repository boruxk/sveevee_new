<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\BudgetedHttpClient;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Http\SourceRequestBudgetExceeded;
use Sveevee\Worker\Research\TelAvivBusinessLicenseSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class AllRecordsTelHttp implements HttpClientInterface
{
    public array $requests = [];

    public ?int $failOffset = null;

    public ?int $repeatOffset = null;

    public function __construct(public array $records) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->requests[] = $query;
        if (isset($query['returnCountOnly'])) {
            return new HttpResponse(200, [], Json::encode(['count' => count($this->records)]));
        }
        $offset = (int) $query['resultOffset'];
        if ($this->failOffset === $offset) {
            return new HttpResponse(503, [], 'Fixture unavailable');
        }
        $rows = array_slice($this->records, $this->repeatOffset === $offset ? 0 : $offset, (int) $query['resultRecordCount']);

        return new HttpResponse(200, [], Json::encode(['features' => array_map(static fn (array $row): array => ['attributes' => $row], $rows)]));
    }
}

final class AllRecordsTelFixture
{
    public readonly Database $database;

    public readonly WorkerRepository $repository;

    public readonly AllRecordsTelHttp $http;

    public function __construct(int $count, ?Closure $change = null)
    {
        $this->database = new Database(':memory:');
        $this->repository = new WorkerRepository($this->database, new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv']), new BusinessMerger);
        $rows = [];
        for ($number = 1; $number <= $count; $number++) {
            $row = [
                'oid_rishayon' => $number, 'ms_esek_rashi' => 1000 + $number, 'ms_esek_mishne' => 0,
                't_shem_esek' => 'License '.$number, 't_hesber_mahut_esek' => 'Original municipal description',
                'taarich_tokef' => '2020-01-01', 'mahuiot' => '999999', 'shem_rechov' => null,
                'license_status' => 'expired',
            ];
            $rows[] = $change === null ? $row : $change($row, $number);
        }
        $this->http = new AllRecordsTelHttp($rows);
    }

    public function source(?HttpClientInterface $http = null, array $overrides = []): TelAvivBusinessLicenseSource
    {
        return new TelAvivBusinessLicenseSource($overrides + [
            'import_mode' => 'all_records', 'page_size' => 10, 'min_interval_seconds' => 0,
            'max_retries' => 0, 'cache_refresh_seconds' => 86400,
        ], $http ?? $this->http, $this->repository, 'TelAllRecordsTest');
    }

    public function consume(int $limit, ?TelAvivBusinessLicenseSource $source = null): array
    {
        $source ??= $this->source();
        $rows = [];
        foreach ($source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), $limit) as $raw) {
            $rows[] = $raw;
            $source->acknowledge($raw);
        }

        return $rows;
    }

    public function state(): array
    {
        return $this->database->pdo->query('SELECT * FROM source_record_scopes')->fetch(PDO::FETCH_ASSOC);
    }

    public function queued(): int
    {
        return (int) $this->database->pdo->query('SELECT COUNT(*) FROM source_record_queue')->fetchColumn();
    }
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$tests = [];
$tests['full mode retains expired unknown licenses, optional addresses and original license details'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(3, static fn (array $row, int $number): array => array_replace($row, [
        'mahuiot' => $number === 1 ? '402100' : ($number === 2 ? "unknown'code" : ''),
        'shem_rechov' => $number === 1 ? 'Original street' : null,
    ]));
    $rows = $f->consume(10);
    $assert(count($rows) === 3 && $rows[0]['category_key'] === 'food_catering.restaurants' && $rows[1]['category_key'] === null, 'A category, expiration or street filter removed a valid named record.');
    $assert($rows[1]['address'] === ['city' => 'Tel Aviv'], 'Missing street was invented or intrinsic city was dropped.');
    foreach ($rows as $index => $raw) {
        $assert(! isset($raw['public_description']) && ! isset($raw['service_areas']), 'Full mode fabricated public description or service areas.');
        $assert($raw['source_metadata']['license'] === $f->http->records[$index], 'Original license values were discarded.');
        parse_str((string) parse_url($raw['source_url'], PHP_URL_QUERY), $query);
        $assert($raw['source_metadata']['source_id'] === '964:'.hash('sha256', $query['where']), 'Stable group ID disagrees with the legacy URL condition.');
    }
    $assert($rows[1]['source_metadata']['source_where'] === "ms_esek_rashi=1002 AND ms_esek_mishne=0 AND mahuiot='unknown''code'", 'License code quotes changed the stable identity condition.');
    $assert($f->queued() === 0 && (int) $f->state()['consumed_offset'] === 3, 'Consumed source JSON was not pruned.');
};
$tests['full mode preserves the existing group URL and ignores unstable row IDs in fingerprints'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(1, static fn (array $row): array => array_replace($row, ['mahuiot' => '402100', 'shem_rechov' => 'Original street', 'taarich_tokef' => '2999-01-01']));
    $legacy = iterator_to_array($f->source(overrides: ['import_mode' => 'catalog'])->research(new ResearchTarget('Tel Aviv', 'food_catering.restaurants'), 1))[0];
    $first = $f->consume(1)[0];
    $assert($first['source_url'] === $legacy['source_url'], 'Full mode changed existing provenance URLs.');
    $f->http->records[0]['oid_rishayon'] = 999;
    $second = $f->consume(1, $f->source(overrides: ['cache_refresh_seconds' => 0]))[0];
    $assert($first['source_metadata']['source_id'] === $second['source_metadata']['source_id'] && SourceFingerprint::hash($first) === SourceFingerprint::hash($second), 'Row renumbering changed source identity or public content fingerprint.');
};
$tests['daily quotas resume ten records at a time without restarting the count or offset'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(23);
    $first = $f->consume(10);
    $assert(count($first) === 10 && count($f->http->requests) === 2 && $f->queued() === 0, 'First quota did not consume exactly one ten-row page after counting.');
    $f->http->requests = [];
    $second = $f->consume(10);
    $assert(count($second) === 10 && count($f->http->requests) === 1 && (int) $f->http->requests[0]['resultOffset'] === 10, 'Second run restarted source paging instead of continuing.');
    $f->http->requests = [];
    $third = $f->consume(10);
    $assert(count($third) === 3 && count($f->http->requests) === 1 && (int) $f->http->requests[0]['resultOffset'] === 20, 'Final partial page was lost.');
    $assert(count(array_unique(array_column(array_merge($first, $second, $third), 'source_url'))) === 23, 'Sequential traversal repeated or omitted source identities.');
    $f->http->requests = [];
    $assert($f->consume(10) === [] && $f->http->requests === [], 'Completed source restarted before its refresh interval.');
};
$tests['a cached page tail is consumed before any new HTTP request'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(12);
    $assert(count($f->consume(5)) === 5 && $f->queued() === 5, 'Stopping mid-page did not retain the other five records.');
    $f->http->requests = [];
    $second = $f->consume(5);
    $assert(count($second) === 5 && $second[0]['name'] === 'License 6' && $f->http->requests === [] && $f->queued() === 0, 'Cached tail caused duplicate source HTTP or wrong resumption.');
    $last = $f->consume(10);
    $assert(count($last) === 2 && (int) $f->http->requests[0]['resultOffset'] === 10, 'The next page did not follow the consumed tail.');
};
$tests['an unacknowledged record survives interruption and acknowledgments are idempotent'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(4);
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), 10);
    $raw = $iterator->current();
    unset($iterator, $source);
    $assert($f->queued() === 4 && (int) $f->state()['consumed_offset'] === 0, 'Yield advanced the source cursor before a durable result.');
    $f->http->requests = [];
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), 10);
    $repeated = $iterator->current();
    $assert($repeated === $raw && $f->http->requests === [], 'Unacknowledged raw was refetched or skipped.');
    $source->acknowledge($repeated);
    $source->acknowledge($repeated);
    unset($iterator, $source);
    $assert((int) $f->state()['consumed_offset'] === 1 && $f->queued() === 3, 'Repeated acknowledgment advanced more than one record.');
    $assert(count($f->consume(10)) === 3, 'Remaining interrupted page was not recoverable.');
};
$tests['the shared ten-request budget pauses at offset ninety and the next run resumes there'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(103);
    $source = $f->source(new BudgetedHttpClient($f->http, 10));
    $count = 0;
    try {
        foreach ($source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), 1000) as $raw) {
            $source->acknowledge($raw);
            $count++;
        }
        throw new LogicException('Request budget was not enforced.');
    } catch (SourceRequestBudgetExceeded) {
        $assert($count === 90 && count($f->http->requests) === 10 && (int) $f->state()['consumed_offset'] === 90, 'Budget stopped at an unsafe or unexpected page boundary.');
    }
    $f->http->requests = [];
    $remaining = $f->consume(1000, $f->source(new BudgetedHttpClient($f->http, 10)));
    $assert(count($remaining) === 13 && count($f->http->requests) === 2 && (int) $f->http->requests[0]['resultOffset'] === 90, 'Budget resumption repeated the count/first page or lost rows.');
};
$tests['source errors retain the consumed position and never trigger retry floods'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(12);
    $f->http->failOffset = 10;
    try {
        $f->consume(100);
        throw new LogicException('HTTP error was silently accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'HTTP 503'), 'Unexpected source failure.');
    }
    $assert(count($f->http->requests) === 3 && (int) $f->state()['consumed_offset'] === 10 && $f->queued() === 0, 'Source error retried or rewound consumed pages.');
    $f->http->failOffset = null;
    $f->http->requests = [];
    $assert(count($f->consume(10)) === 2 && (int) $f->http->requests[0]['resultOffset'] === 10, 'Source error prevented later recovery.');
};
$tests['a shrinking dataset ends cleanly after a budgeted count refresh'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(12);
    $f->consume(10);
    $f->http->records = array_slice($f->http->records, 0, 8);
    $f->http->requests = [];
    $assert($f->consume(10) === [] && count($f->http->requests) === 2 && isset($f->http->requests[1]['returnCountOnly']), 'Shrinking dataset was not re-counted or became a permanent empty-page error.');
    $assert((int) $f->state()['expected_total'] === 8 && (int) $f->state()['complete'] === 1, 'Updated source total was not retained.');
};
$tests['refresh never discards a partially consumed page and final acknowledgment completes the cycle'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(10);
    $f->consume(5, $f->source(overrides: ['cache_refresh_seconds' => 0]));
    $f->http->requests = [];
    $tail = $f->consume(5, $f->source(overrides: ['cache_refresh_seconds' => 0]));
    $assert(count($tail) === 5 && $tail[0]['name'] === 'License 6' && $f->http->requests === [], 'Refresh reset a partially consumed page.');
    $assert($f->database->pdo->query('SELECT completed_at FROM source_record_scans')->fetchColumn() !== null, 'The last quota acknowledgment did not complete the cycle.');
    $next = $f->consume(10, $f->source(overrides: ['cache_refresh_seconds' => 0]));
    $assert(count($next) === 10 && count($f->http->requests) === 2, 'A completed source failed to refresh on the next eligible run.');
};
$tests['unchanged successful records are acknowledged before the emitted limit'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(2);
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), 1);
    $raw = $iterator->current();
    unset($iterator, $source);
    $f->database->pdo->prepare('INSERT INTO researched_urls (adapter, url_hash, source_url, status, checked_at, raw_hash) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['tel_aviv_business_licenses', hash('sha256', $raw['source_url']), $raw['source_url'], 'success', Clock::now(), SourceFingerprint::hash($raw)]);
    $f->http->requests = [];
    $rows = $f->consume(1);
    $assert(count($rows) === 1 && $rows[0]['name'] === 'License 2' && $f->http->requests === [] && $f->queued() === 0, 'Cached successful source consumed quota or blocked the scan.');
};
$tests['full normalization retains a previously imported municipal source and its page association'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(1, static fn (array $row): array => array_replace($row, ['mahuiot' => '402100', 'shem_rechov' => 'Original street', 'taarich_tokef' => '2999-01-01']));
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv']);
    $legacy = iterator_to_array($f->source(overrides: ['import_mode' => 'catalog'])->research(new ResearchTarget('Tel Aviv', 'food_catering.restaurants'), 1))[0];
    $stored = $f->repository->upsertCandidate($normalizer->normalize($legacy, new ResearchTarget('Tel Aviv', 'food_catering.restaurants'), 'tel_aviv_business_licenses'));
    $f->repository->markBusiness($stored['business_id'], 'imported', 77);
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), 1);
    $raw = $iterator->current();
    $assert(is_array($raw) && $raw['source_url'] === $legacy['source_url'], 'Legacy success was detached when new full-mode payload fields changed.');
    $updated = $f->repository->upsertCandidate($normalizer->normalize($raw, ResearchTarget::sourceAll('tel_aviv_business_licenses'), 'tel_aviv_business_licenses'));
    $source->acknowledge($raw);
    $business = $f->repository->business($stored['business_id']);
    $assert($updated['business_id'] === $stored['business_id'] && $business['sveevee_page_id'] === 77, 'Full normalization created a replacement worker business or lost its remote page ID.');
    $assert(($business['payload']['source']['provider'] ?? null) === 'tel_aviv_business_licenses', 'Existing municipal business did not gain validated source provenance.');
};
$tests['previously rejected unchanged source records are reconsidered by the complete scan'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(1);
    $source = $f->source();
    $iterator = $source->research(ResearchTarget::sourceAll('tel_aviv_business_licenses'), 1);
    $raw = $iterator->current();
    unset($iterator, $source);
    $f->database->pdo->prepare('INSERT INTO researched_urls (adapter, url_hash, source_url, status, checked_at, raw_hash) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['tel_aviv_business_licenses', hash('sha256', $raw['source_url']), $raw['source_url'], 'rejected', Clock::now(), SourceFingerprint::hash($raw)]);
    $f->http->requests = [];
    $rows = $f->consume(1);
    $assert(count($rows) === 1 && $rows[0]['source_url'] === $raw['source_url'] && $f->queued() === 0 && $f->http->requests === [], 'Legacy rejection was acknowledged without reconsidering the source record.');
};
$tests['repeated source pages fail visibly without advancing the last consumed position'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(12);
    $f->http->repeatOffset = 10;
    try {
        $f->consume(100);
        throw new LogicException('Repeated page was accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'repeated or reordered'), 'Unexpected pagination failure.');
    }
    $assert((int) $f->state()['consumed_offset'] === 10 && (int) $f->state()['next_offset'] === 10 && $f->queued() === 0, 'Malformed page changed the durable position.');
    $f->http->repeatOffset = null;
    $assert(count($f->consume(10)) === 2, 'A corrected source page could not resume at the preserved offset.');
};
$tests['invalid names or group identifiers reach the rejection pipeline with original evidence'] = static function () use ($assert): void {
    $f = new AllRecordsTelFixture(2, static fn (array $row, int $number): array => array_replace($row, $number === 1 ? ['t_shem_esek' => null] : ['ms_esek_rashi' => null]));
    $rows = $f->consume(10);
    $assert(count($rows) === 2 && $rows[0]['name'] === null && ! isset($rows[1]['source_url']) && ! isset($rows[1]['source_metadata']['source_id']), 'Invalid record was silently filtered or given a fabricated source identity.');
    $assert($rows[1]['source_metadata']['license']['ms_esek_rashi'] === null && $f->queued() === 0, 'Rejection evidence or cursor acknowledgment was lost.');
};
$tests['missing required source columns stop the whole page without rejecting or acknowledging its rows'] = static function () use ($assert): void {
    foreach (['t_shem_esek', 'ms_esek_rashi', 'ms_esek_mishne'] as $field) {
        $f = new AllRecordsTelFixture(2);
        $original = $f->http->records[1];
        unset($f->http->records[1][$field]);
        try {
            $f->consume(10);
            throw new LogicException('Missing source column was treated as an invalid individual record.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'source schema is missing required field '.$field), 'Unexpected source schema error.');
        }
        $assert($f->queued() === 0 && (int) $f->state()['next_offset'] === 0 && (int) $f->state()['consumed_offset'] === 0, 'A partially validated page was persisted or acknowledged.');
        $f->http->records[1] = $original;
        $assert(count($f->consume(10)) === 2, 'Correcting the source schema failed to recover all page rows.');
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL);
    }
}
restore_error_handler();
fwrite(STDOUT, count($tests).' tests, '.$failures.' failures'.PHP_EOL);
exit($failures === 0 ? 0 : 1);
