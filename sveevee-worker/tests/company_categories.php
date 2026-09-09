<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Research\Ckan\CompanyCategoryClassifier;

$cases = [
    ['מאפיית השחר בע״מ', '', 'food_catering.bakery'],
    ['קונדיטוריה לדוגמה בעמ', '', 'food_catering.bakery'],
    ['מסעדת החוף בעמ', '', 'food_catering.restaurants'],
    ['פלאפל הגן בעמ', '', 'professionals.fast_food'],
    ['פיצרייה לדוגמה בעמ', '', 'professionals.fast_food'],
    ['פיצריית בדיקה בעמ', '', 'professionals.fast_food'],
    ['המבורגר השחר בעמ', '', 'professionals.fast_food'],
    ['בורגרים השחר בעמ', '', 'professionals.fast_food'],
    ['בית אוכל השחר בעמ', '', 'food_catering.restaurants'],
    ['צימר השחר בעמ', '', 'travel_leisure.hotels_guesthouses'],
    ['בתי קפה הגן בעמ', '', 'food_catering.cafes'],
    ['קיטרינג השחר בעמ', '', 'professionals.catering'],
    ['מרכולים לדוגמה בעמ', '', 'professionals.grocery_food'],
    ['סופרמארקט לדוגמה בעמ', '', 'professionals.grocery_food'],
    ['מעדניית השחר בעמ', '', 'food_catering.meat_deli'],
    ['אטליז לדוגמה בעמ', '', 'food_catering.meat_deli'],
    ['פאב הגן בעמ', '', 'food_catering.bars'],
    ['אולמי אירועים לדוגמה בעמ', '', 'professionals.venues'],
    ['מלונות החוף בעמ', '', 'travel_leisure.hotels_guesthouses'],
    ['עסק לדוגמה בעמ', 'DAWN BAKERY LTD', 'food_catering.bakery'],
    ['עסק לדוגמה בעמ', 'DAWN RESTAURANTS LTD', 'food_catering.restaurants'],
    ['עסק לדוגמה בעמ', 'DAWN PIZZERIA LTD', 'professionals.fast_food'],
    ['עסק לדוגמה בעמ', 'DAWN CAFÉ LTD', 'food_catering.cafes'],
    ['עסק לדוגמה בעמ', 'DAWN CATERING LTD', 'professionals.catering'],
    ['עסק לדוגמה בעמ', 'DAWN GROCERY LTD', 'professionals.grocery_food'],
    ['עסק לדוגמה בעמ', 'DAWN DELICATESSEN LTD', 'food_catering.meat_deli'],
    ['עסק לדוגמה בעמ', 'DAWN WINE BAR LTD', 'food_catering.bars'],
    ['עסק לדוגמה בעמ', 'DAWN EVENT VENUE LTD', 'professionals.venues'],
    ['עסק לדוגמה בעמ', 'DAWN HOTELS LTD', 'travel_leisure.hotels_guesthouses'],
    ['מִסְעֶדֶת—החוף בע״מ', '', 'food_catering.restaurants'],
    ['מסעדת פיצה לדוגמה בעמ', '', 'professionals.fast_food'],
    ['חברה לדוגמה בעמ', '', null],
    ['בר כהן בעמ', 'BAR COHEN LTD', null],
    ['קפה לדוגמה בעמ', 'DAWN COFFEE LTD', null],
    ['עסק לדוגמה', 'BARCELONA CONSULTING LTD', null],
    ['יבוא ציוד למסעדות בעמ', '', null],
    ['שיווק פיצה בעמ', '', null],
    ['השקעות מלונות החוף בעמ', '', null],
    ['מלון וציוד בעמ', '', null],
    ['מכולות הגן בעמ', '', null],
    ['עסק לדוגמה', 'HOTEL EQUIPMENT LTD', null],
    ['עסק לדוגמה', 'RESTAURANT SUPPLIERS LTD', null],
    ['עסק לדוגמה', 'BAKERY PACKAGING LTD', null],
    ['עסק לדוגמה', 'CAFE SOFTWARE LTD', null],
    ['עסק לדוגמה', 'HOTEL HOLDINGS LTD', null],
    ['עסק לדוגמה', 'BARBARA LTD', null],
    ['מסעדת החוף', 'COAST HOTEL LTD', null],
    ['מאפייה ובית קפה בעמ', '', null],
];
$assertions = 0;
foreach ($cases as [$name, $englishName, $expected]) {
    $actual = CompanyCategoryClassifier::classify($name, $englishName, 'לעסוק בכל עיסוק חוקי');
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected category: '.json_encode([$name, $englishName, $expected, $actual], JSON_UNESCAPED_UNICODE));
    }
    $assertions++;
}
foreach ([
    ['לעסוק בסוגי עיסוק שפורטו בתקנון', '', null],
    ['הפעלת בית קפה', '', 'food_catering.cafes'],
    ['', 'הפעלת אולם אירועים', 'professionals.venues'],
    ['הפעלת בית קפה ומלון', '', null],
    ['יבוא ציוד לבתי קפה', '', null],
] as [$purpose, $description, $expected]) {
    $actual = CompanyCategoryClassifier::classify('חברה לדוגמה בעמ', '', $purpose, $description);
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected purpose classification.');
    }
    $assertions++;
}
if (count(CompanyCategoryClassifier::CATEGORY_KEYS) !== 10) {
    throw new RuntimeException('The register classifier must retain the ten configured categories.');
}
fwrite(STDOUT, "Company classification: {$assertions} assertions passed.\n");
