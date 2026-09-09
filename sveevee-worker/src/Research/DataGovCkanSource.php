<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Research\Ckan\BeerShevaBusinessLicenseProfile;
use Sveevee\Worker\Research\Ckan\CkanAllRecordsProfileInterface;
use Sveevee\Worker\Research\Ckan\CkanDatasetProfileInterface;
use Sveevee\Worker\Research\Ckan\CkanScopedDatasetProfileInterface;
use Sveevee\Worker\Research\Ckan\IsraelCompaniesProfile;
use Sveevee\Worker\Storage\SourcePageCache;
use Sveevee\Worker\Storage\SourceRecordCursor;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Pacer;
use Sveevee\Worker\Support\SourceFingerprint;

final class DataGovCkanSource implements CursorSourceInterface
{
    private readonly string $apiUrl;

    private readonly Pacer $pacer;

    private array $openedScopes = [];

    private readonly SourcePageCache $pageCache;

    private ?SourceRecordCursor $recordCursor = null;

    private ?string $scanKey = null;

    private ?array $outstanding = null;

    /** @var array<string, CkanDatasetProfileInterface> */
    private array $profiles;

    public function __construct(
        private readonly array $config,
        private readonly HttpClientInterface $http,
        private readonly WorkerRepository $repository,
        private readonly string $userAgent,
    ) {
        $this->apiUrl = rtrim((string) ($config['api_url'] ?? 'https://data.gov.il/api/3/action'), '/');
        if (strtolower((string) parse_url($this->apiUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('The Data.gov.il CKAN API URL must use HTTPS.');
        }
        $this->pacer = new Pacer((int) round(max(0.0, (float) ($config['min_interval_seconds'] ?? 2.0)) * 1000));
        $this->profiles = [];
        foreach ([new BeerShevaBusinessLicenseProfile, new IsraelCompaniesProfile] as $profile) {
            $this->profiles[$profile->name()] = $profile;
        }
        if ($this->datasets() === []) {
            throw new RuntimeException('Data.gov.il CKAN is enabled but no datasets are configured.');
        }
        $this->pageCache = $repository->sourcePageCache();
    }

    public function name(): string
    {
        return 'data_gov_ckan';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 365));
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        if (($this->config['import_mode'] ?? 'catalog') === 'all_records') {
            if ($target->fullSourceProvider() === $this->name() && $limit > 0) {
                yield from $this->allRecords($limit);
            }

            return;
        }
        $emitted = 0;
        foreach ($this->datasets() as $dataset) {
            $profile = $this->profile($dataset);
            if (! $profile->supports($dataset, $target)) {
                continue;
            }
            foreach ($this->recordsFor($dataset, $profile, $target) as $cached) {
                $record = $cached['record'];
                if (! is_array($record) || ($recordId = $profile->recordId($record)) === null) {
                    continue;
                }
                $sourceUrl = $this->recordUrl($dataset, $profile, $recordId);
                $business = $profile->map($record, $dataset, $target, $sourceUrl, $cached['checked_at']);
                if ($business === null) {
                    continue;
                }
                if (! $this->repository->shouldProcessUrl(
                    $this->name(),
                    $sourceUrl,
                    $this->refreshAfterDays(),
                    SourceFingerprint::hash($business),
                )) {
                    continue;
                }

                yield $business;
                $emitted++;
                if ($emitted >= max(1, $limit)) {
                    return;
                }
            }
        }
    }

    public function acknowledge(array $raw): void
    {
        if ($this->outstanding === null) {
            return;
        }
        if ($this->outstanding['hash'] !== Json::hash($raw)) {
            throw new RuntimeException('Cannot acknowledge a different CKAN record.');
        }
        $pending = $this->outstanding;
        $this->recordCursor->acknowledge($this->scanKey, $pending['scope'], $pending['position'], $pending['cycle']);
        $this->outstanding = null;
    }

    private function allRecords(int $limit): iterable
    {
        $datasets = [];
        foreach ($this->datasets() as $dataset) {
            $id = trim((string) ($dataset['resource_id'] ?? ''));
            if ($id === '' || isset($datasets[$id]) || ! $this->profile($dataset) instanceof CkanAllRecordsProfileInterface) {
                throw new RuntimeException('Full CKAN scans require unique resource IDs and a supported full-record profile.');
            }
            $datasets[$id] = $dataset;
        }
        if ($this->recordCursor === null) {
            $this->recordCursor = $this->repository->sourceRecordCursor();
            $this->scanKey = 'data_gov_ckan:'.Json::hash(['schema' => 'ckan-all-records-v1', 'api' => $this->apiUrl, 'datasets' => array_values($datasets)]);
            $this->recordCursor->open($this->scanKey, $this->name(), array_keys($datasets), (int) ($this->config['cache_refresh_seconds'] ?? 86400));
        }
        $emitted = 0;
        while (($scope = $this->recordCursor->currentScope($this->scanKey)) !== null) {
            $dataset = $datasets[$scope];
            $profile = $this->profile($dataset);
            $state = $this->recordCursor->state($this->scanKey, $scope);
            $rows = $this->recordCursor->pending($this->scanKey, $scope);
            if ($rows === []) {
                $this->fetchFullPage($scope, $profile, $state);

                continue;
            }
            foreach ($rows as $row) {
                $recordId = $profile->recordId($row['record']);
                $url = $recordId === null
                    ? $this->apiUrl.'/datastore_search?'.http_build_query(['resource_id' => $scope, 'limit' => 1, 'filters' => Json::encode(['_id' => (int) $row['record_id']])], '', '&', PHP_QUERY_RFC3986)
                    : $this->recordUrl($dataset, $profile, $recordId);
                $business = $profile->mapAll($row['record'], $dataset, $url, $row['checked_at']);
                $this->outstanding = ['hash' => Json::hash($business), 'scope' => $scope, 'position' => $row['position'], 'cycle' => (int) $state['cycle']];
                if ($recordId !== null && ! $this->repository->shouldProcessUrl(
                    $this->name(), $url, $this->refreshAfterDays(), SourceFingerprint::hash($business), reconsiderLegacySource: true,
                )) {
                    $this->acknowledge($business);

                    continue;
                }
                yield $business;
                if ($this->outstanding !== null) {
                    throw new RuntimeException('A CKAN record must be durably acknowledged before advancing its scan.');
                }
                if (++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    private function fetchFullPage(string $resourceId, CkanAllRecordsProfileInterface $profile, array $state): void
    {
        $offset = (int) $state['next_offset'];
        $maximum = max(1, (int) ($this->config['max_records_per_full_dataset'] ?? 2_000_000));
        $pageSize = max(1, min(10, (int) ($this->config['page_size'] ?? 10)));
        if ($offset >= $maximum) {
            throw new RuntimeException('Full CKAN scan reached max_records_per_full_dataset.');
        }
        $requested = min($pageSize, $maximum - $offset);
        $payload = $this->request($this->apiUrl.'/datastore_search?'.http_build_query([
            'resource_id' => $resourceId, 'limit' => $requested, 'offset' => $offset,
            'include_total' => 'true', 'total_estimation_threshold' => 0,
        ] + $profile->fullSearchParameters(), '', '&', PHP_QUERY_RFC3986));
        $result = $payload['result'] ?? null;
        if (! is_array($result) || ! is_array($result['records'] ?? null)
            || ! isset($result['total']) || filter_var($result['total'], FILTER_VALIDATE_INT) === false
            || (int) $result['total'] < 0 || ($result['total_was_estimated'] ?? false) !== false) {
            throw new RuntimeException('Full CKAN scan requires records and an exact nonnegative total.');
        }
        if ((int) $result['total'] > $maximum || count($result['records']) > $requested) {
            throw new RuntimeException('Full CKAN page or resource exceeds its configured bound.');
        }
        $priorIds = $state['last_page_ids'] === null ? [] : Json::decode($state['last_page_ids']);
        $previous = $priorIds === [] ? -1 : (int) end($priorIds);
        $rows = [];
        foreach ($result['records'] as $record) {
            $id = is_array($record) ? ($record['_id'] ?? null) : null;
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= $previous) {
                throw new RuntimeException('Full CKAN scan requires strictly increasing numeric datastore row IDs.');
            }
            $previous = (int) $id;
            $profile->assertRecordSchema($record);
            $rows[] = ['id' => (string) $id, 'record' => $record];
        }
        // Changing totals are expected in a live register; the last durable offset stays intact.
        $this->recordCursor->append($this->scanKey, $resourceId, $offset, (int) $result['total'], $rows, Clock::now(),
            max(1, (int) ($this->config['max_cache_bytes'] ?? 134217728)));
    }

    private function datasets(): array
    {
        return array_values(array_filter(
            (array) ($this->config['datasets'] ?? []),
            static fn ($dataset): bool => is_array($dataset),
        ));
    }

    private function profile(array $dataset): CkanDatasetProfileInterface
    {
        $name = trim((string) ($dataset['profile'] ?? ''));
        if (! isset($this->profiles[$name])) {
            throw new RuntimeException("Unknown Data.gov.il dataset profile: {$name}");
        }

        return $this->profiles[$name];
    }

    private function recordsFor(array $dataset, CkanDatasetProfileInterface $profile, ResearchTarget $target): iterable
    {
        $resourceId = trim((string) ($dataset['resource_id'] ?? ''));
        if ($resourceId === '') {
            throw new RuntimeException('A Data.gov.il dataset is missing resource_id.');
        }
        $scoped = $profile instanceof CkanScopedDatasetProfileInterface;
        $parameters = $scoped ? $profile->searchParameters($dataset, $target) : ['sort' => '_id asc'];
        // Category is deliberately absent: all ten categories share the same validated city pages.
        $cacheKey = Json::hash(['schema' => 'ckan-pages-v1', 'api' => $this->apiUrl, 'dataset' => $dataset, 'parameters' => $parameters]);
        if (! isset($this->openedScopes[$cacheKey])) {
            $this->pageCache->open($cacheKey, $this->name(), (int) ($this->config['cache_refresh_seconds'] ?? 86400));
            $this->openedScopes[$cacheKey] = true;
        }
        $pageSize = max(1, min(10, (int) ($this->config['page_size'] ?? 10)));
        $maximumKey = $scoped ? 'max_records_per_city' : 'max_records_per_dataset';
        $maximum = max(1, (int) ($this->config[$maximumKey] ?? ($scoped ? 100_000 : 50_000)));
        $state = $this->pageCache->state($cacheKey);
        if ($state['expected_total'] !== null && (int) $state['expected_total'] > $maximum) {
            throw new RuntimeException("Data.gov.il cached scope exceeds configured {$maximumKey} {$maximum}.");
        }
        yield from $this->pageCache->records($cacheKey);
        while (! (bool) $state['complete']) {
            $offset = (int) $state['next_offset'];
            $url = $this->apiUrl.'/datastore_search?'.http_build_query([
                'resource_id' => $resourceId,
                'limit' => min($pageSize, $maximum - $offset),
                'offset' => $offset,
                'include_total' => 'true',
                'total_estimation_threshold' => 0,
            ] + $parameters, '', '&', PHP_QUERY_RFC3986);
            // HTTP/budget failures preserve previous pages and the next offset exactly as committed.
            $payload = $this->request($url);
            try {
                $result = $payload['result'] ?? null;
                if (! is_array($result) || ! is_array($result['records'] ?? null)) {
                    throw new RuntimeException('Data.gov.il returned an invalid datastore_search result.');
                }
                if (! isset($result['total']) || filter_var($result['total'], FILTER_VALIDATE_INT) === false
                    || (int) $result['total'] < 0 || ($result['total_was_estimated'] ?? false) !== false) {
                    throw new RuntimeException('Data.gov.il did not return the requested exact record count.');
                }
                $total = (int) $result['total'];
                if ($state['expected_total'] !== null && $total !== (int) $state['expected_total']) {
                    throw new RuntimeException('Data.gov.il record count changed during pagination.');
                }
                if ($total > $maximum) {
                    throw new RuntimeException("Data.gov.il resource {$resourceId} scope contains {$total} records, exceeding configured {$maximumKey} {$maximum}.");
                }
                $page = $result['records'];
                $received = count($page);
                if (($received === 0 && $offset < $total) || $offset + $received > $total || $received > min($pageSize, $maximum - $offset)) {
                    throw new RuntimeException('Data.gov.il returned incomplete or inconsistent pagination.');
                }
                $rows = [];
                foreach ($page as $record) {
                    if (! is_array($record) || ! isset($record['_id']) || filter_var($record['_id'], FILTER_VALIDATE_INT) === false) {
                        throw new RuntimeException('Data.gov.il returned a record without a valid datastore row ID.');
                    }
                    // Count all raw rows and remember their IDs; store large JSON only for eligible categories.
                    $rows[] = ['id' => (string) $record['_id'], 'record' => ! $scoped || $profile->keepRecord($record, $dataset, $target) ? $record : null];
                }
                $checkedAt = Clock::now();
                $state = $this->pageCache->append($cacheKey, $offset, $total, $rows, $checkedAt, max(1, (int) ($this->config['max_cache_bytes'] ?? 134217728)));
            } catch (\Throwable $exception) {
                $this->pageCache->discard($cacheKey);
                unset($this->openedScopes[$cacheKey]);
                throw new RuntimeException($exception->getMessage().' Cached scope was discarded; the next run will restart its scan.', 0, $exception);
            }
            // The entire validated page is durable before the caller can stop at its successful-write limit.
            foreach ($rows as $row) {
                if ($row['record'] !== null) {
                    yield ['record' => $row['record'], 'checked_at' => $checkedAt];
                }
            }
        }
    }

    private function request(string $url): array
    {
        $maxRetries = max(0, min(6, (int) ($this->config['max_retries'] ?? 0)));
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->pacer->wait();
            $response = $this->http->request('GET', $url, ['Accept' => 'application/json'], null, [
                'timeout' => max(5, (int) ($this->config['timeout_seconds'] ?? 30)),
                'max_bytes' => max(1_048_576, (int) ($this->config['max_response_bytes'] ?? 10_485_760)),
                'user_agent' => $this->userAgent,
            ]);
            if ($response->status >= 200 && $response->status < 300) {
                $decoded = Json::decode($response->body);
                if (! is_array($decoded) || ($decoded['success'] ?? false) !== true) {
                    throw new RuntimeException('Data.gov.il returned an unsuccessful CKAN response.');
                }

                return $decoded;
            }
            if (! in_array($response->status, [429, 500, 502, 503, 504], true) || $attempt === $maxRetries) {
                throw new RuntimeException("Data.gov.il request failed with HTTP {$response->status}.");
            }
            $retryAfter = (int) ($response->header('Retry-After') ?? 0);
            sleep($retryAfter > 0 ? min(60, $retryAfter) : min(30, 2 ** ($attempt + 1)));
        }

        throw new RuntimeException('Data.gov.il request failed.');
    }

    private function recordUrl(array $dataset, CkanDatasetProfileInterface $profile, string $recordId): string
    {
        $resourceId = trim((string) ($dataset['resource_id'] ?? ''));

        return $this->apiUrl.'/datastore_search?'.http_build_query([
            'resource_id' => $resourceId,
            'limit' => 1,
            'filters' => Json::encode($profile instanceof CkanScopedDatasetProfileInterface
                ? $profile->recordFilters($recordId)
                : ['_id' => ctype_digit($recordId) ? (int) $recordId : $recordId]),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
