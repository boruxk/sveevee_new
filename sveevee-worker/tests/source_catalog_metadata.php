<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Research\Ckan\BeerShevaBusinessLicenseProfile;
use Sveevee\Worker\Research\Ckan\IsraelCompaniesProfile;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Research\SourceCatalogMetadata;
use Sveevee\Worker\Research\TelAvivBusinessLicenseSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\SourceFingerprint;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$repository = new WorkerRepository(new Database(':memory:'), new BusinessNormalizer(new OpeningHoursParser, ['Haifa', 'Beersheba', 'Tel Aviv']), new BusinessMerger);
$noHttp = new class implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        throw new RuntimeException('Metadata mapping must not perform HTTP requests.');
    }
};
$tel = new TelAvivBusinessLicenseSource(['import_mode' => 'all_records'], $noHttp, $repository, 'SveeveeTest/1.0');
$telMap = new ReflectionMethod($tel, 'mapAll');
$license = static fn (string $codes, string $description): array => [
    'oid_rishayon' => 1, 'ms_esek_rashi' => 20, 'ms_esek_mishne' => 0, 't_shem_esek' => 'עסק',
    'mahuiot' => $codes, 't_hesber_mahut_esek' => $description, 'shem_rechov' => null,
];
$tests = [];

$tests['Overture retains primary and alternate source categories without adding generic hierarchy ancestors'] = static function () use ($assert): void {
    $input = ['address' => ['locality' => 'כפר מקור'], 'taxonomy' => [
        'primary' => 'seafood_restaurant', 'hierarchy' => ['eat_and_drink', 'restaurant', 'seafood_restaurant'],
        'alternates' => ['bakery', 'seafood_restaurant', 'unknown_original_category'],
    ], 'basic_category' => 'eat_and_drink'];
    $meta = SourceCatalogMetadata::overture($input);
    $assert($meta['source_city'] === 'כפר מקור', 'Raw source locality was replaced.');
    $assert(array_column($meta['source_categories'], 'key') === ['seafood_restaurant', 'bakery', 'unknown_original_category'], 'Primary/alternate ordering or hierarchy filtering is wrong.');
    $assert(array_column($meta['source_categories'], 'catalog_key') === ['food_catering.restaurants', 'food_catering.bakery', null], 'Independent category mappings are wrong.');
    $assert($meta['source_categories'][2]['label'] === 'unknown original category', 'Unknown taxonomy label is not readable.');
};

$tests['basic-category fallback applies only when primary and alternates are absent'] = static function () use ($assert): void {
    $meta = SourceCatalogMetadata::overture(['taxonomy' => ['hierarchy' => ['services']], 'basic_category' => 'unknown_basic']);
    $assert($meta['source_categories'] === [['key' => 'unknown_basic', 'label' => 'unknown basic', 'catalog_key' => null]], 'Missing taxonomy lost its actual basic category.');
    $meta = SourceCatalogMetadata::overture(['taxonomy' => ['alternates' => ['bakery']], 'basic_category' => 'unknown_basic']);
    $assert(array_column($meta['source_categories'], 'key') === ['bakery'], 'Basic category should not duplicate actual categories.');
    $assert(SourceCatalogMetadata::overture([]) === ['source_city' => null, 'source_categories' => []], 'Absent source facts were invented.');
};

$tests['prepared Overture metadata is enriched at read time without fingerprint or legacy changes'] = static function () use ($assert, $repository): void {
    $row = ['id' => 'gers-fixture', 'name' => 'Place', 'category_key' => null, 'city' => 'Unknown city', 'street' => null,
        'phone' => null, 'email' => null, 'website' => null, 'social_links' => '{}', 'confidence' => 0.8,
        'release' => '2026-08-19.0', 'source_url' => 'https://explore.overturemaps.org/?feature=places.place.gers-fixture',
        'source_name' => 'Overture Maps Places', 'source_checked_at' => '2026-09-01T00:00:00+00:00',
        'source_metadata' => Json::encode(['address' => ['country' => 'IL', 'locality' => 'Original locality'], 'taxonomy' => ['primary' => 'unknown_category']]),
    ];
    $legacy = new OverturePlacesSource(['import_mode' => 'catalog'], dirname(__DIR__), $repository);
    $full = new OverturePlacesSource(['import_mode' => 'all_places'], dirname(__DIR__), $repository);
    $map = new ReflectionMethod(OverturePlacesSource::class, 'map');
    $old = $map->invoke($legacy, $row);
    $new = $map->invoke($full, $row);
    $assert(! array_key_exists('source_categories', $old['source_metadata']), 'Legacy mapper metadata changed.');
    $assert($new['source_metadata']['source_city'] === 'Original locality' && $new['source_metadata']['source_categories'][0]['key'] === 'unknown_category', 'Existing prepared metadata was not enriched.');
    $assert(SourceFingerprint::hash($old) === SourceFingerprint::hash($new), 'Metadata-only changes invalidated old source fingerprints.');
    unset($old['source_metadata'], $new['source_metadata']);
    $assert($old === $new, 'Business content changed during metadata extraction.');
};

$tests['company legal purpose and description never become invented source categories'] = static function () use ($assert): void {
    $row = ['_id' => 1, 'מספר חברה' => 510000001, 'שם חברה' => 'עסק', 'שם עיר' => 'חיפה',
        'מטרת החברה' => 'לעסוק בכל עיסוק חוקי', 'תאור חברה' => 'חברה פרטית'];
    $mapped = (new IsraelCompaniesProfile)->mapAll($row, ['resource_id' => 'resource', 'city_names' => ['Haifa' => ['חיפה']]], 'https://example.org/record', '2026-09-01');
    $assert($mapped['address']['city'] === 'Haifa' && $mapped['source_metadata']['source_city'] === 'חיפה', 'Canonical city and raw label were not kept separately.');
    $assert($mapped['source_metadata']['source_categories'] === [] && $mapped['source_metadata']['original_record'] === $row, 'Legal purpose was promoted to a business category.');
};

$tests['Beersheba category descriptors use actual license text rather than the business name'] = static function () use ($assert): void {
    $row = ['_id' => 1, 'שם עסק' => 'מסעדה כלשהי', 'תאור רישיון' => 'סוג רישיון מקורי לא מוכר'];
    $mapped = (new BeerShevaBusinessLicenseProfile)->mapAll($row, ['resource_id' => 'resource'], 'https://example.org/record', '2026-09-01');
    $category = $mapped['source_metadata']['source_categories'][0];
    $assert($mapped['category_key'] === 'food_catering.restaurants', 'Business category unexpectedly changed.');
    $assert($category['catalog_key'] === null && $category['label'] === $row['תאור רישיון'], 'Business name caused a false license-label mapping.');
    $assert($category['key'] === 'license_description:'.hash('sha256', $row['תאור רישיון']) && $mapped['source_metadata']['source_city'] === 'באר שבע', 'Source descriptor is unstable or locality was invented.');
};

$tests['Tel Aviv preserves a single license code with its actual activity description'] = static function () use ($assert, $tel, $telMap, $license): void {
    $mapped = $telMap->invoke($tel, $license('402100', 'מסעדה'), '2026-09-01');
    $assert($mapped['source_metadata']['source_categories'] === [['key' => 'license_code:402100', 'label' => 'מסעדה', 'catalog_key' => 'food_catering.restaurants']], 'Single-code activity descriptor is wrong.');
    $assert($mapped['source_metadata']['source_city'] === 'תל אביב-יפו', 'Municipal source locality label is missing.');
};

$tests['Tel Aviv multiple codes are not given a fabricated individual description'] = static function () use ($assert, $tel, $telMap, $license): void {
    $mapped = $telMap->invoke($tel, $license('402100;999999;402100', 'תיאור משותף'), '2026-09-01');
    $categories = $mapped['source_metadata']['source_categories'];
    $assert(array_column($categories, 'key') === ['license_code:402100', 'license_code:999999'], 'License codes were lost or repeated.');
    $assert(array_column($categories, 'label') === ['402100', '999999'] && $categories[1]['catalog_key'] === null, 'Aggregate description was incorrectly assigned to each code.');
};

$tests['Tel Aviv description without a code remains reviewable and absent data stays absent'] = static function () use ($assert, $tel, $telMap, $license): void {
    $mapped = $telMap->invoke($tel, $license('', 'פעילות מקורית'), '2026-09-01');
    $assert($mapped['source_metadata']['source_categories'][0]['key'] === 'license_description:'.hash('sha256', 'פעילות מקורית'), 'Code-free activity description was discarded.');
    $empty = $telMap->invoke($tel, $license('', ''), '2026-09-01');
    $assert($empty['source_metadata']['source_categories'] === [], 'Missing activity was invented.');
};

$tests['descriptor limits preserve deterministic full-label identity'] = static function () use ($assert): void {
    $value = str_repeat('א', 600);
    $descriptor = SourceCatalogMetadata::description($value, null)[0];
    $assert(mb_strlen($descriptor['label'], 'UTF-8') === 500 && $descriptor['key'] === 'license_description:'.hash('sha256', $value), 'Long labels lost their stable original identity.');
    $meta = SourceCatalogMetadata::overture(['address' => ['locality' => str_repeat('א', 140)], 'taxonomy' => ['primary' => str_repeat('x', 300)]]);
    $assert(mb_strlen($meta['source_city'], 'UTF-8') === 120 && strlen($meta['source_categories'][0]['key']) === 255, 'Descriptor length bounds differ from backend contract.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, '[PASS] '.$name.PHP_EOL);
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, '[FAIL] '.$name.': '.$error->getMessage().PHP_EOL);
    }
}
fwrite(STDOUT, count($tests).' tests, '.$failures.' failures'.PHP_EOL);
exit($failures > 0 ? 1 : 0);
