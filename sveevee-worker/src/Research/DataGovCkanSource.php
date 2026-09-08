<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Research\Ckan\BeerShevaBusinessLicenseProfile;
use Sveevee\Worker\Research\Ckan\CkanDatasetProfileInterface;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Pacer;
use Sveevee\Worker\Support\SourceFingerprint;

final class DataGovCkanSource implements SourceAdapterInterface
{
    private readonly string $apiUrl;
    private readonly Pacer $pacer;
    private array $records = [];

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
        $this->pacer = new Pacer((int) round(max(0.0, (float) ($config['min_interval_seconds'] ?? 0.5)) * 1000));
        $profile = new BeerShevaBusinessLicenseProfile;
        $this->profiles = [$profile->name() => $profile];
        if ($this->datasets() === []) {
            throw new RuntimeException('Data.gov.il CKAN is enabled but no datasets are configured.');
        }
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
        $emitted = 0;
        foreach ($this->datasets() as $dataset) {
            $profile = $this->profile($dataset);
            if (! $profile->supports($dataset, $target)) {
                continue;
            }
            $checkedAt = Clock::now();
            foreach ($this->recordsFor($dataset) as $record) {
                if (! is_array($record) || ($recordId = $profile->recordId($record)) === null) {
                    continue;
                }
                $sourceUrl = $this->recordUrl($dataset, $recordId);
                $business = $profile->map($record, $dataset, $target, $sourceUrl, $checkedAt);
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

    private function recordsFor(array $dataset): array
    {
        $resourceId = trim((string) ($dataset['resource_id'] ?? ''));
        if ($resourceId === '') {
            throw new RuntimeException('A Data.gov.il dataset is missing resource_id.');
        }
        if (isset($this->records[$resourceId])) {
            return $this->records[$resourceId];
        }

        $pageSize = max(1, min(5000, (int) ($this->config['page_size'] ?? 1000)));
        $maximum = max($pageSize, (int) ($this->config['max_records_per_dataset'] ?? 50_000));
        $records = [];
        $offset = 0;
        $expectedTotal = null;

        do {
            $url = $this->apiUrl.'/datastore_search?'.http_build_query([
                'resource_id' => $resourceId,
                'limit' => min($pageSize, $maximum - $offset),
                'offset' => $offset,
                'include_total' => 'true',
            ], '', '&', PHP_QUERY_RFC3986);
            $payload = $this->request($url);
            $result = $payload['result'] ?? null;
            if (! is_array($result) || ! is_array($result['records'] ?? null)) {
                throw new RuntimeException('Data.gov.il returned an invalid datastore_search result.');
            }
            $page = $result['records'];
            $expectedTotal ??= isset($result['total']) ? (int) $result['total'] : null;
            if ($expectedTotal !== null && $expectedTotal > $maximum) {
                throw new RuntimeException(
                    "Data.gov.il resource {$resourceId} contains {$expectedTotal} records, exceeding configured max_records_per_dataset {$maximum}."
                );
            }
            $records = [...$records, ...$page];
            $received = count($page);
            $offset += $received;
        } while ($received > 0 && $offset < ($expectedTotal ?? $maximum) && $offset < $maximum);

        return $this->records[$resourceId] = $records;
    }

    private function request(string $url): array
    {
        $maxRetries = max(0, min(6, (int) ($this->config['max_retries'] ?? 3)));
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

    private function recordUrl(array $dataset, string $recordId): string
    {
        $resourceId = trim((string) ($dataset['resource_id'] ?? ''));

        return $this->apiUrl.'/datastore_search?'.http_build_query([
            'resource_id' => $resourceId,
            'limit' => 1,
            'filters' => Json::encode(['_id' => ctype_digit($recordId) ? (int) $recordId : $recordId]),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
