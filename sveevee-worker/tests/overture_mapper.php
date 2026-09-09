<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use App\Support\CatalogTopics;
use Sveevee\Worker\Research\Overture\PlaceMapper;
use Sveevee\Worker\Research\Overture\TaxonomyMapper;
use Sveevee\Worker\Support\Json;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$row = static fn (): array => [
    'id' => '08f347c000000001', 'names' => ['primary' => 'מאפיית בדיקה בע״מ'],
    'addresses' => [['country' => 'IL', 'locality' => 'חיפה', 'freeform' => 'הרצל 10']],
    'phones' => ['04-1234567'], 'emails' => ['Hello@Example.org'], 'websites' => ['example.org'],
    'socials' => ['https://www.instagram.com/test'],
    'taxonomy' => ['primary' => 'bakery', 'hierarchy' => ['food_and_drink', 'bakery']],
    'confidence' => 0.9, 'operating_status' => null, 'sources' => [['dataset' => 'meta']],
];
$legacy = new PlaceMapper(['Haifa'], ['Haifa' => ['חיפה']], 0.75);
$full = PlaceMapper::fromConfig([
    'cities' => ['Haifa'],
    'sources' => ['overture_places' => ['import_mode' => 'all_places', 'min_confidence' => 0.75, 'city_names' => ['Haifa' => ['חיפה']]]],
]);
$map = static fn (PlaceMapper $mapper, array $raw): ?array => $mapper->map($raw, '2026-08-19.0', '2026-09-09T00:00:00Z');
$tests = [];
$tests['full mode preserves legacy payloads and canonical address choice'] = static function () use ($assert, $row, $map, $legacy, $full): void {
    $raw = $row();
    array_unshift($raw['addresses'], ['country' => 'IL', 'locality' => 'Unknown village', 'freeform' => 'Other road 5']);
    $before = $map($legacy, $raw);
    $after = $map($full, $raw);
    $assert(Json::encode($before) === Json::encode($after), 'Existing accepted rows must keep category, address, contacts and GERS URL.');
    $assert($after['city'] === 'Haifa' && $after['street'] === 'הרצל 10', 'New address support changed the legacy address choice.');
    $assert($legacy->importMode() === 'catalog' && $full->importMode() === 'all_places', 'Mode accessor or config activation failed.');
};
$tests['full mode accepts low confidence, closure and uncategorized records without fabricated values'] = static function () use ($assert, $row, $map, $legacy, $full): void {
    $raw = $row();
    $raw['confidence'] = 0.013;
    $raw['operating_status'] = 'permanently_closed';
    $raw['taxonomy'] = null;
    $raw['addresses'] = [['country' => 'IL']];
    $mapped = $map($full, $raw);
    $assert($mapped !== null && $map($legacy, $raw) === null, 'Full mode retained a legacy exclusion.');
    $assert($mapped['category_key'] === null && $mapped['city'] === null && $mapped['street'] === null, 'Absent category or address must remain null.');
    $assert($mapped['confidence'] === 0.013 && $mapped['source_metadata']['operating_status'] === 'permanently_closed', 'Original low-confidence and closure provenance was lost.');
};
$tests['unknown IL localities survive while punctuation, absent and overlong address fields remain null'] = static function () use ($assert, $row, $map, $full): void {
    $raw = $row();
    $raw['addresses'] = [['country' => 'US', 'locality' => 'Wrong city', 'freeform' => 'Wrong street'], ['country' => 'IL', 'locality' => '  כפר   חדש ', 'freeform' => '---']];
    $mapped = $map($full, $raw);
    $assert($mapped['city'] === 'כפר חדש' && $mapped['street'] === null, 'Original IL locality should survive without a fabricated street.');
    $raw['addresses'][1]['locality'] = str_repeat('x', 121);
    $raw['addresses'][1]['region'] = 'Do not infer a city';
    $raw['addresses'][1]['postcode'] = '1234567';
    $raw['addresses'][1]['freeform'] = 'Named road';
    $mapped = $map($full, $raw);
    $assert($mapped['city'] === null && $mapped['street'] === 'Named road', 'Missing locality was guessed from region/postcode or a foreign address.');
    $assert($mapped['source_metadata']['address'] === $raw['addresses'][1], 'Unusable original fields must remain available in provenance.');
};
$tests['full mode prefers an available locality over an incomplete first IL address'] = static function () use ($assert, $row, $map, $full): void {
    $raw = $row();
    $raw['addresses'] = [['country' => 'IL', 'freeform' => 'Road 1'], ['country' => 'IL', 'locality' => 'Small village']];
    $mapped = $map($full, $raw);
    $assert($mapped['city'] === 'Small village' && $mapped['street'] === null, 'Do not combine unrelated fields from different addresses.');
};
$tests['ID, name and Israel provenance are still required'] = static function () use ($assert, $row, $map, $full): void {
    foreach (['id', 'name', 'country'] as $case) {
        $raw = $row();
        match ($case) {
            'id' => $raw['id'] = '../invalid',
            'name' => $raw['names']['primary'] = 'בע״מ',
            'country' => $raw['addresses'] = [['country' => 'US', 'locality' => 'Haifa', 'freeform' => 'Road']],
        };
        $assert($map($full, $raw) === null, 'Invalid '.$case.' was accepted.');
    }
};
$tests['optional confidence and coordinates retain honest source provenance'] = static function () use ($assert, $row, $map, $full): void {
    $raw = $row();
    $raw['bbox'] = ['xmin' => 35.0, 'ymin' => 32.0, 'xmax' => 35.0, 'ymax' => 32.0];
    $raw['coordinates'] = [35.0, 32.0];
    $raw['basic_category'] = 'casual_eatery';
    foreach ([null, 'unknown', NAN, INF, -0.1, 1.1] as $confidence) {
        $raw['confidence'] = $confidence;
        $mapped = $map($full, $raw);
        $assert($mapped !== null && $mapped['confidence'] === null, 'Unavailable confidence should not drop a valid IL place or become zero.');
        $assert($mapped['source_metadata']['bbox'] === $raw['bbox'] && $mapped['source_metadata']['coordinates'] === $raw['coordinates'], 'Supplied geometry provenance was dropped.');
        $assert($mapped['source_metadata']['basic_category'] === 'casual_eatery', 'Original basic category must survive even when primary taxonomy is absent.');
        Json::encode($mapped);
    }
};
$tests['existing category priority remains stable in both modes'] = static function () use ($assert): void {
    foreach ([
        ['primary' => 'food_truck_stand', 'hierarchy' => ['restaurant'], 'expected' => 'professionals.fast_food'],
        ['primary' => 'gastropub', 'hierarchy' => ['restaurant'], 'expected' => 'food_catering.bars'],
        ['primary' => 'delicatessen', 'hierarchy' => ['grocery_store'], 'expected' => 'food_catering.meat_deli'],
        ['primary' => 'italian_restaurant', 'hierarchy' => ['restaurant'], 'expected' => 'food_catering.restaurants'],
    ] as $case) {
        $assert(TaxonomyMapper::map($case) === $case['expected'] && TaxonomyMapper::map($case, true) === $case['expected'], 'Previous food category changed.');
    }
};
$tests['broad exact categories and the most specific ancestor map to corresponding catalog topics'] = static function () use ($assert): void {
    foreach ([
        'beauty_salon' => 'professionals.beauty_salons', 'hair_salon' => 'professionals.hair_salons',
        'nail_salon' => 'beauty_personal_care.nails', 'pharmacy' => 'health_care.pharmacies',
        'accountant' => 'legal_finance_business.accounting', 'insurance_agency' => 'legal_finance_business.insurance',
        'plumbing' => 'services.home_repairs.plumbing', 'jewelry_store' => 'shopping_retail.jewelry_watches',
        'automotive_repair' => 'professionals.garages', 'veterinarian' => 'professionals.veterinarians',
        'language_school' => 'professionals.language_lessons', 'software_development' => 'professional_business.web_it',
    ] as $primary => $expected) {
        $assert(TaxonomyMapper::map(['primary' => $primary], true) === $expected, 'Wrong broad category: '.$primary);
        $assert(TaxonomyMapper::map(['primary' => $primary]) === null, 'Legacy mode unexpectedly accepted broad category: '.$primary);
    }
    $assert(TaxonomyMapper::map(['primary' => 'cosmetic_dentistry', 'hierarchy' => ['health_care', 'outpatient_care_facility', 'dental_clinic']], true) === 'health_care.dentists', 'More specific ancestor must win.');
    $assert(TaxonomyMapper::map(['primary' => 'accountant', 'hierarchy' => ['financial_service']], true) === 'legal_finance_business.accounting', 'Primary category must beat a broad ancestor.');
};
$tests['unmatched public places and ambiguous commercial meanings stay uncategorized and are retained'] = static function () use ($assert, $row, $map, $full): void {
    foreach (['government_office', 'jewish_place_of_worship', 'beach', 'school', 'professional_service', 'real_estate_service', 'gym', 'manufacturer', 'shopping', 'cafeteria', 'private_lodging'] as $primary) {
        $raw = $row();
        $raw['taxonomy'] = ['primary' => $primary, 'hierarchy' => []];
        $mapped = $map($full, $raw);
        $assert($mapped !== null && $mapped['category_key'] === null, 'Unmatched '.$primary.' was dropped or misleadingly categorized.');
        $assert($mapped['source_metadata']['taxonomy'] === $raw['taxonomy'], 'Original unmatched taxonomy must remain visible in provenance.');
    }
};
$tests['every broad mapping points to a real category enabled for business pages'] = static function () use ($assert): void {
    $autoload = dirname(__DIR__, 2).'/backend/vendor/autoload.php';
    if (! is_file($autoload)) {
        fwrite(STDOUT, "SKIP catalog integration: backend dependencies are unavailable.\n");

        return;
    }
    require_once $autoload;
    $keys = CatalogTopics::keysForScope(CatalogTopics::SCOPE_BUSINESS_PAGES);
    $constants = (new ReflectionClass(TaxonomyMapper::class))->getConstants();
    foreach (array_merge($constants['PRIMARY'], $constants['FAMILIES']) as $source => $key) {
        $assert(in_array($key, $keys, true), $source.' maps to an invalid business category '.$key);
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL);
    }
}
fwrite(STDOUT, count($tests).' tests, '.$failed.' failures'.PHP_EOL);
exit($failed === 0 ? 0 : 1);
