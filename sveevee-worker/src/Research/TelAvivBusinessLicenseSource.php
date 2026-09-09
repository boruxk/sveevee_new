<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Pacer;
use Sveevee\Worker\Support\SourceFingerprint;

/** Official Tel Aviv municipal license register, ArcGIS layer 964. */
final class TelAvivBusinessLicenseSource implements SourceAdapterInterface
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

    private ?array $records = null;

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
        $this->pacer = new Pacer((int) round(max(0.0, (float) ($config['min_interval_seconds'] ?? 0.5)) * 1000));
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
        if ($target->city !== 'Tel Aviv' || $target->neighborhood !== null
            || ! isset(self::CATEGORY_LABELS[$target->categoryKey])) {
            return;
        }
        $emitted = 0;
        $seen = [];
        $checkedAt = Clock::now();
        foreach ($this->records() as $record) {
            $business = $this->map($record, $checkedAt);
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

    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }
        $pageSize = max(1, min(2000, (int) ($this->config['page_size'] ?? 1000)));
        $maximum = max($pageSize, (int) ($this->config['max_records_per_dataset'] ?? 50_000));
        $count = $this->request(['where' => '1=1', 'returnCountOnly' => 'true']);
        if (! isset($count['count']) || ! is_int($count['count']) || $count['count'] < 0) {
            throw new RuntimeException('Tel Aviv returned an invalid license count.');
        }
        $total = $count['count'];
        if ($total > $maximum) {
            throw new RuntimeException("Tel Aviv has {$total} license records, exceeding max_records_per_dataset {$maximum}.");
        }
        $records = [];
        $seenIds = [];
        for ($offset = 0; $offset < $total;) {
            $response = $this->request([
                'where' => '1=1',
                'outFields' => 'oid_rishayon,ms_esek_rashi,ms_esek_mishne,t_shem_esek,t_hesber_mahut_esek,taarich_tokef,mahuiot,shem_rechov',
                'returnGeometry' => 'false',
                'orderByFields' => 'oid_rishayon ASC',
                'resultOffset' => $offset,
                'resultRecordCount' => min($pageSize, $total - $offset),
            ]);
            if (! is_array($response['features'] ?? null) || $response['features'] === []) {
                throw new RuntimeException('Tel Aviv license pagination ended before the reported record count.');
            }
            foreach ($response['features'] as $feature) {
                $record = $feature['attributes'] ?? null;
                $id = is_array($record) ? ($record['oid_rishayon'] ?? null) : null;
                if (! is_int($id) || isset($seenIds[$id])) {
                    throw new RuntimeException('Tel Aviv returned missing or repeated license row identifiers during pagination.');
                }
                $seenIds[$id] = true;
                $records[] = $record;
            }
            $offset += count($response['features']);
            if ($offset > $maximum) {
                throw new RuntimeException('Tel Aviv license pagination exceeded max_records_per_dataset.');
            }
        }

        return $this->records = $records;
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
        $retries = max(0, min(6, (int) ($this->config['max_retries'] ?? 3)));
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
