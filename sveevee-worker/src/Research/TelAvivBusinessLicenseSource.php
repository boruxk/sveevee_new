<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Storage\SourcePageCache;
use Sveevee\Worker\Storage\SourceRecordCursor;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Pacer;
use Sveevee\Worker\Support\SourceFingerprint;

/** Official Tel Aviv municipal license register, ArcGIS layer 964. */
final class TelAvivBusinessLicenseSource implements CursorSourceInterface
{
    public const DEFAULT_LAYER_URL = 'https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964';

    private const CATEGORY_LABELS = [
        'food_catering.bakery' => 'מאפייה',
        'food_catering.restaurants' => 'מסעדה',
        'professionals.fast_food' => 'מזון מהיר',
        'food_catering.cafes' => 'בית קפה',
        'professionals.catering' => 'שירותי קייטרינג',
        'professionals.grocery_food' => 'חנות מזון',
        'food_catering.meat_deli' => 'מעדנייה',
        'food_catering.bars' => 'בר',
        'professionals.venues' => 'מקום לאירועים',
        'travel_leisure.hotels_guesthouses' => 'מלון או מקום אירוח',
    ];

    private readonly string $layerUrl;

    private readonly Pacer $pacer;

    private bool $cacheOpened = false;

    private readonly SourcePageCache $pageCache;

    private ?SourceRecordCursor $recordCursor = null;

    private bool $fullScanOpened = false;

    private ?array $outstanding = null;

    public function __construct(
        private readonly array $config,
        private readonly HttpClientInterface $http,
        private readonly WorkerRepository $repository,
        private readonly string $userAgent,
    ) {
        $this->layerUrl = rtrim((string) ($config['layer_url'] ?? self::DEFAULT_LAYER_URL), '/');
        if (strtolower((string) parse_url($this->layerUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('The Tel Aviv business license API must use HTTPS.');
        }
        $this->pacer = new Pacer((int) round(max(0.0, (float) ($config['min_interval_seconds'] ?? 2.0)) * 1000));
        $this->pageCache = $repository->sourcePageCache();
    }

    public function name(): string
    {
        return 'tel_aviv_business_licenses';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 365));
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        if (($this->config['import_mode'] ?? 'catalog') === 'all_records') {
            if ($target->isFullSource() && $target->fullSourceProvider() === $this->name()) {
                yield from $this->allRecords($limit);
            }

            return;
        }
        if ($target->city !== 'Tel Aviv' || $target->neighborhood !== null
            || ! isset(self::CATEGORY_LABELS[$target->categoryKey])) {
            return;
        }
        $emitted = 0;
        $seen = [];
        foreach ($this->records() as $cached) {
            $business = $this->map($cached['record'], $cached['checked_at']);
            if ($business === null || $business['category_key'] !== $target->categoryKey) {
                continue;
            }
            $hash = SourceFingerprint::hash($business);
            $key = $business['source_url'].'|'.$hash;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (! $this->repository->shouldProcessUrl($this->name(), $business['source_url'], $this->refreshAfterDays(), $hash)) {
                continue;
            }
            yield $business;
            if (++$emitted >= max(1, $limit)) {
                return;
            }
        }
    }

    public function acknowledge(array $raw): void
    {
        if ($this->outstanding === null) {
            return;
        }
        if (! hash_equals($this->outstanding['hash'], SourceFingerprint::hash($raw))) {
            throw new RuntimeException('Cannot acknowledge a different Tel Aviv source record.');
        }
        $this->recordCursor->acknowledge(
            $this->outstanding['scan'], $this->outstanding['scope'],
            $this->outstanding['position'], $this->outstanding['cycle'],
        );
        $this->outstanding = null;
    }

    private function allRecords(int $limit): iterable
    {
        $scan = Json::hash(['schema' => 'tel-aviv-all-records-v1', 'layer' => $this->layerUrl]);
        $scope = $this->layerUrl;
        $this->recordCursor ??= $this->repository->sourceRecordCursor();
        if (! $this->fullScanOpened) {
            $this->recordCursor->open($scan, $this->name(), [$scope], (int) ($this->config['cache_refresh_seconds'] ?? 86400));
            $this->fullScanOpened = true;
        }
        $pageSize = max(1, min(10, (int) ($this->config['page_size'] ?? 10)));
        $maximum = max(1, (int) ($this->config['max_records_per_scan'] ?? 2_000_000));
        $emitted = 0;
        while ($this->recordCursor->currentScope($scan) !== null) {
            $state = $this->recordCursor->state($scan, $scope);
            foreach ($this->recordCursor->pending($scan, $scope) as $cached) {
                $business = $this->mapAll($cached['record'], $cached['checked_at']);
                $hash = SourceFingerprint::hash($business);
                $this->outstanding = [
                    'scan' => $scan, 'scope' => $scope, 'position' => $cached['position'],
                    'cycle' => $state['cycle'], 'hash' => $hash,
                ];
                if (isset($business['source_url']) && ! $this->repository->shouldProcessUrl(
                    $this->name(), $business['source_url'], $this->refreshAfterDays(), $hash,
                    reconsiderLegacySource: true,
                )) {
                    $this->acknowledge($business);

                    continue;
                }
                yield $business;
                // A stopped consumer must leave this row available for the next run.
                if ($this->outstanding !== null || ++$emitted >= max(1, $limit)) {
                    return;
                }
            }
            if ($this->recordCursor->currentScope($scan) === null) {
                return;
            }
            $state = $this->recordCursor->state($scan, $scope);
            if ($state['expected_total'] === null) {
                $this->recordCursor->setTotal($scan, $scope, $this->licenseCount($maximum));

                continue;
            }
            $total = (int) $state['expected_total'];
            if ($total > $maximum) {
                throw new RuntimeException("Tel Aviv cached scope exceeds max_records_per_scan {$maximum}.");
            }
            $offset = (int) $state['next_offset'];
            $requested = min($pageSize, $total - $offset);
            $response = $this->request([
                'where' => '1=1', 'outFields' => '*', 'returnGeometry' => 'false',
                'orderByFields' => 'oid_rishayon ASC', 'resultOffset' => $offset,
                'resultRecordCount' => $requested,
            ]);
            if (! is_array($response['features'] ?? null)) {
                throw new RuntimeException('Tel Aviv returned a missing or invalid license page.');
            }
            if ($response['features'] === []) {
                $updatedTotal = $this->licenseCount($maximum);
                $this->recordCursor->setTotal($scan, $scope, $updatedTotal);
                if ($offset >= $updatedTotal) {
                    continue;
                }
                throw new RuntimeException('Tel Aviv license pagination ended before the reported record count.');
            }
            if (count($response['features']) > $requested) {
                throw new RuntimeException('Tel Aviv returned more licenses than its requested page or reported total.');
            }
            $rows = [];
            $previousIds = $state['last_page_ids'] === null ? [] : Json::decode($state['last_page_ids']);
            $previousId = $previousIds === [] ? null : (int) end($previousIds);
            foreach ($response['features'] as $feature) {
                $record = $feature['attributes'] ?? null;
                $id = is_array($record) ? ($record['oid_rishayon'] ?? null) : null;
                if (! is_int($id)) {
                    throw new RuntimeException('Tel Aviv returned a missing or invalid license row identifier.');
                }
                $this->validateFullSchema($record);
                if ($previousId !== null && $id <= $previousId) {
                    throw new RuntimeException('Tel Aviv repeated or reordered a license row during sequential pagination.');
                }
                $previousId = $id;
                $rows[] = ['id' => (string) $id, 'record' => $record];
            }
            $this->recordCursor->append(
                $scan, $scope, $offset, $total, $rows, Clock::now(),
                max(1, (int) ($this->config['max_cache_bytes'] ?? 134217728)),
            );
        }
    }

    private function licenseCount(int $maximum): int
    {
        $count = $this->request(['where' => '1=1', 'returnCountOnly' => 'true']);
        if (! isset($count['count']) || ! is_int($count['count']) || $count['count'] < 0) {
            throw new RuntimeException('Tel Aviv returned an invalid license count.');
        }
        if ($count['count'] > $maximum) {
            throw new RuntimeException("Tel Aviv has {$count['count']} license records, exceeding max_records_per_scan {$maximum}.");
        }

        return $count['count'];
    }

    private function mapAll(array $record, string $checkedAt): array
    {
        $this->validateFullSchema($record);
        $name = $this->text($record['t_shem_esek'] ?? null);
        $street = $this->text($record['shem_rechov'] ?? null);
        $main = filter_var($record['ms_esek_rashi'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sub = filter_var($record['ms_esek_mishne'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $codes = $this->text($record['mahuiot'] ?? null) ?? '';
        $description = $this->text($record['t_hesber_mahut_esek'] ?? null) ?? '';
        $codeList = array_values(array_unique(preg_split('/[^0-9]+/', $codes, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $sourceCategories = [];
        foreach ($codeList as $code) {
            $label = count($codeList) === 1 && $description !== '' ? $description : $code;
            $sourceCategories[] = ['key' => 'license_code:'.$code, 'label' => SourceCatalogMetadata::text($label),
                'catalog_key' => $this->category('', count($codeList) === 1 ? $description : '', $code)];
        }
        if ($sourceCategories === []) {
            $sourceCategories = SourceCatalogMetadata::description($description, $this->category('', $description, ''));
        }
        $business = [
            'type' => 'business', 'name' => $name,
            'category_key' => $this->category($name ?? '', $description, $codes),
            'address' => array_filter(['street' => $street, 'city' => 'Tel Aviv'], static fn (mixed $value): bool => $value !== null),
            'source_name' => 'Tel Aviv-Yafo municipal business licenses',
            'source_checked_at' => $checkedAt,
            'source_metadata' => [
                'country' => 'IL', 'layer_id' => '964', 'original_name' => $record['t_shem_esek'] ?? null,
                'source_city' => 'תל אביב-יפו', 'source_categories' => $sourceCategories,
                'license' => $record,
            ],
        ];
        if ($main !== false && $sub !== false) {
            $where = "ms_esek_rashi={$main} AND ms_esek_mishne={$sub} AND mahuiot='".str_replace("'", "''", $codes)."'";
            $business['source_url'] = $this->url(['where' => $where, 'outFields' => '*', 'returnGeometry' => 'false']);
            $business['source_metadata']['source_id'] = '964:'.hash('sha256', $where);
            $business['source_metadata']['source_where'] = $where;
        }

        return $business;
    }

    private function validateFullSchema(array $record): void
    {
        foreach (['t_shem_esek', 'ms_esek_rashi', 'ms_esek_mishne'] as $field) {
            if (! array_key_exists($field, $record)) {
                throw new RuntimeException('Tel Aviv source schema is missing required field '.$field.'.');
            }
        }
    }

    private function records(): iterable
    {
        $key = Json::hash(['schema' => 'tel-aviv-pages-v1', 'layer' => $this->layerUrl]);
        if (! $this->cacheOpened) {
            $this->pageCache->open($key, $this->name(), (int) ($this->config['cache_refresh_seconds'] ?? 86400));
            $this->cacheOpened = true;
        }
        $pageSize = max(1, min(10, (int) ($this->config['page_size'] ?? 10)));
        $maximum = max(1, (int) ($this->config['max_records_per_dataset'] ?? 50_000));
        $state = $this->pageCache->state($key);
        if ($state['expected_total'] === null) {
            $count = $this->request(['where' => '1=1', 'returnCountOnly' => 'true']);
            if (! isset($count['count']) || ! is_int($count['count']) || $count['count'] < 0) {
                throw new RuntimeException('Tel Aviv returned an invalid license count.');
            }
            if ($count['count'] > $maximum) {
                throw new RuntimeException("Tel Aviv has {$count['count']} license records, exceeding max_records_per_dataset {$maximum}.");
            }
            $this->pageCache->setTotal($key, $count['count']);
            $state = $this->pageCache->state($key);
        }
        $total = (int) $state['expected_total'];
        if ($total > $maximum) {
            throw new RuntimeException("Tel Aviv cached scope exceeds max_records_per_dataset {$maximum}.");
        }
        yield from $this->pageCache->records($key);
        while (! (bool) $state['complete']) {
            $offset = (int) $state['next_offset'];
            $response = $this->request([
                'where' => '1=1',
                'outFields' => 'oid_rishayon,ms_esek_rashi,ms_esek_mishne,t_shem_esek,t_hesber_mahut_esek,taarich_tokef,mahuiot,shem_rechov',
                'returnGeometry' => 'false',
                'orderByFields' => 'oid_rishayon ASC',
                'resultOffset' => $offset,
                'resultRecordCount' => min($pageSize, $total - $offset),
            ]);
            try {
                if (! is_array($response['features'] ?? null) || $response['features'] === []) {
                    throw new RuntimeException('Tel Aviv license pagination ended before the reported record count.');
                }
                if (count($response['features']) > min($pageSize, $total - $offset)) {
                    throw new RuntimeException('Tel Aviv returned more licenses than its requested page or reported total.');
                }
                $rows = [];
                foreach ($response['features'] as $feature) {
                    $record = $feature['attributes'] ?? null;
                    $id = is_array($record) ? ($record['oid_rishayon'] ?? null) : null;
                    if (! is_int($id)) {
                        throw new RuntimeException('Tel Aviv returned a missing or invalid license row identifier.');
                    }
                    $rows[] = ['id' => (string) $id, 'record' => $record];
                }
                $checkedAt = Clock::now();
                $state = $this->pageCache->append($key, $offset, $total, $rows, $checkedAt, max(1, (int) ($this->config['max_cache_bytes'] ?? 134217728)));
            } catch (\Throwable $exception) {
                $this->pageCache->discard($key);
                $this->cacheOpened = false;
                throw new RuntimeException($exception->getMessage().' Cached scope was discarded; the next run will restart its scan.', 0, $exception);
            }
            foreach ($rows as $row) {
                yield ['record' => $row['record'], 'checked_at' => $checkedAt];
            }
        }
    }

    private function map(array $record, string $checkedAt): ?array
    {
        $name = $this->text($record['t_shem_esek'] ?? null);
        $street = $this->text($record['shem_rechov'] ?? null);
        $main = filter_var($record['ms_esek_rashi'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sub = filter_var($record['ms_esek_mishne'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $expiry = $this->text($record['taarich_tokef'] ?? null);
        if ($name === null || $street === null || $main === false || $sub === false
            || $expiry === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $expiry) !== 1) {
            return null;
        }
        $expiryDay = DateTimeImmutable::createFromFormat('!Y-m-d', substr($expiry, 0, 10), new DateTimeZone('Asia/Jerusalem'));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($expiryDay === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $expiryDay < new DateTimeImmutable('today', new DateTimeZone('Asia/Jerusalem'))) {
            return null;
        }
        $codes = $this->text($record['mahuiot'] ?? null) ?? '';
        $description = $this->text($record['t_hesber_mahut_esek'] ?? null) ?? '';
        $category = $this->category($name, $description, $codes);
        if ($category === null) {
            return null;
        }
        // ArcGIS row OIDs may be reassigned on refresh. Business/sub-business and license type remain meaningful identifiers.
        $where = "ms_esek_rashi={$main} AND ms_esek_mishne={$sub} AND mahuiot='".str_replace("'", "''", $codes)."'";

        return [
            'type' => 'business',
            'name' => $name,
            'public_description' => $name.' - '.self::CATEGORY_LABELS[$category].' בתל אביב-יפו.',
            'category_key' => $category,
            'address' => ['street' => $street, 'city' => 'Tel Aviv'],
            'service_areas' => ['Tel Aviv'],
            'source_name' => 'Tel Aviv-Yafo municipal business licenses',
            'source_url' => $this->url(['where' => $where, 'outFields' => '*', 'returnGeometry' => 'false']),
            'source_checked_at' => $checkedAt,
        ];
    }

    private function category(string $name, string $description, string $codes): ?string
    {
        $nameValue = mb_strtolower($name, 'UTF-8');
        $value = mb_strtolower($name.' '.$description, 'UTF-8');
        $codeList = preg_split('/[^0-9]+/', $codes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hasPrefix = static function (string $prefix) use ($codeList): bool {
            foreach ($codeList as $code) {
                if (str_starts_with($code, $prefix)) {
                    return true;
                }
            }

            return false;
        };
        if ($hasPrefix('7011')) {
            return 'travel_leisure.hotels_guesthouses';
        }
        if ($hasPrefix('709')) {
            return 'professionals.venues';
        }
        if ($hasPrefix('4065')) {
            return 'professionals.catering';
        }
        if ($hasPrefix('408')) {
            return 'food_catering.bars';
        }
        if ($hasPrefix('4073') || in_array('407205', $codeList, true)) {
            return 'food_catering.meat_deli';
        }
        if ($hasPrefix('407')) {
            return 'professionals.grocery_food';
        }
        if (in_array('406103', $codeList, true)) {
            return 'food_catering.bakery';
        }
        // Other manufacturing/storage licenses are not classified as public restaurants from incidental food words.
        if (! $hasPrefix('402')) {
            return null;
        }
        if (in_array('402203', $codeList, true) || in_array('402215', $codeList, true)) {
            return 'food_catering.cafes';
        }
        if (preg_match('/מאפי[יה]|קונדיטורי|bakery/u', $nameValue) === 1) {
            return 'food_catering.bakery';
        }
        if (preg_match('/פיצ[אה]|פלאפל|שווארמה|שוארמה|המבורגר|בורגר|חומוס|pizza|falafel|burger/u', $nameValue) === 1) {
            return 'professionals.fast_food';
        }
        if (preg_match('/קפה|קופי|ארומה|coffee|cafe/u', $nameValue) === 1) {
            return 'food_catering.cafes';
        }
        if (preg_match('/בית\s*קפה/u', $description) === 1 && ! str_contains($description, 'מסעדה')) {
            return 'food_catering.cafes';
        }
        if (preg_match('/פיצ[אה]|פלאפל|שווארמה|שוארמה/u', $value) === 1 && ! $hasPrefix('4021')) {
            return 'professionals.fast_food';
        }
        if ($hasPrefix('4021') || str_contains($description, 'מסעדה')) {
            return 'food_catering.restaurants';
        }
        if (str_contains($description, 'מזנון') || str_contains($description, 'כריכים')) {
            return 'professionals.fast_food';
        }

        return null;
    }

    private function request(array $query): array
    {
        $retries = max(0, min(6, (int) ($this->config['max_retries'] ?? 0)));
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $this->pacer->wait();
            $response = $this->http->request('GET', $this->url($query), ['Accept' => 'application/json'], null, [
                'timeout' => max(5, (int) ($this->config['timeout_seconds'] ?? 30)),
                'max_bytes' => max(1_048_576, (int) ($this->config['max_response_bytes'] ?? 10_485_760)),
                'user_agent' => $this->userAgent,
            ]);
            if ($response->status >= 200 && $response->status < 300) {
                $decoded = Json::decode($response->body);
                if (! is_array($decoded) || isset($decoded['error'])) {
                    throw new RuntimeException('Tel Aviv returned an unsuccessful ArcGIS response.');
                }

                return $decoded;
            }
            if (! in_array($response->status, [429, 500, 502, 503, 504], true) || $attempt === $retries) {
                throw new RuntimeException("Tel Aviv license request failed with HTTP {$response->status}.");
            }
            $retryAfter = (int) ($response->header('Retry-After') ?? 0);
            sleep($retryAfter > 0 ? min(60, $retryAfter) : min(30, 2 ** ($attempt + 1)));
        }
        throw new RuntimeException('Tel Aviv license request failed.');
    }

    private function url(array $query): string
    {
        return $this->layerUrl.'/query?'.http_build_query($query + ['f' => 'json'], '', '&', PHP_QUERY_RFC3986);
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
