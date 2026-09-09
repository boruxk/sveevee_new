<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Ckan;

/** Conservative activity matching for company-register text, not a license check. */
final class CompanyCategoryClassifier
{
    public const CATEGORY_KEYS = [
        'food_catering.bakery',
        'food_catering.restaurants',
        'professionals.fast_food',
        'food_catering.cafes',
        'professionals.catering',
        'professionals.grocery_food',
        'food_catering.meat_deli',
        'food_catering.bars',
        'professionals.venues',
        'travel_leisure.hotels_guesthouses',
    ];

    private const PATTERNS = [
        'food_catering.bakery' => 'מאפי(?:ה|יה|ות|ית)|מאפיית|קונדיטורי(?:ה|ות|ית)|קונדיטוריית|פטיסרי|baker(?:y|ies)|patisserie|boulangerie',
        'food_catering.restaurants' => 'מסעד(?:ה|ות|ת)|(?:בית|בתי) אוכל|restaurants?|bistro|brasserie',
        'professionals.fast_food' => 'פיצ(?:ה|ות|ריה|רייה|ריות|ריית)|פלאפל|שווארמה|שוארמה|המבורגר(?:ים)?|בורגר(?:ים)?|מזון מהיר|pizzas?|pizzerias?|falafel|shawarma|hamburgers?|burgers?|fast food',
        'food_catering.cafes' => 'בית קפה|בתי קפה|cafes?|coffee shops?|coffeehouses?|coffee houses?',
        'professionals.catering' => 'קייטרינג|קיטרינג|הסעדה|catering|caterers?',
        'professionals.grocery_food' => 'מכולת|מרכול(?:ים)?|סופרמ(?:א)?רקט(?:ים)?|חנו(?:ת|יות) נוחות|supermarkets?|grocery|groceries|grocers?|convenience stores?',
        'food_catering.meat_deli' => 'אטליז(?:ים)?|מעדני(?:ה|יה|ות|ית)|מעדניית|butchers?|butchery|delis?|delicatessens?',
        'food_catering.bars' => 'פאב(?:ים)?|בר יין|ברים|גסטרו בר|pubs?|taverns?|wine bars?|cocktail bars?|sports bars?|bar and grill',
        'professionals.venues' => '(?:אולם|אולמי|גן|גני|מרכז) (?:אירועים|ארועים|שמחות|כנסים)|event venues?|banquet halls?|wedding halls?|event halls?|conference cent(?:er|re)s?',
        'travel_leisure.hotels_guesthouses' => 'מלון|מלונות|אכסני(?:ה|ות|ית)|אכסניית|צימר(?:ים)?|hotels?|hostels?|motels?|guest houses?|guesthouses?',
    ];

    // A company supplying hotel equipment is not evidence of a hotel open to guests.
    private const INDIRECT_ACTIVITY = 'השקעות|אחזקות|החזקות|נכסים|נדלן|נדל ן|בניה|בנייה|קבלנות|ייעוץ|יעוץ|יועצים|ניהול|תוכנה|טכנולוגי(?:ה|ות)|שיווק|יבוא|ייבוא|יצוא|ייצוא|סחר|מסחר|ציוד|מכונות|אספקה|ספקים|סיטונאות|יצור|ייצור|מפעל(?:ים|י)?|אריזות|פרסום|קליי?ת|קלייה|קליה|הובלות|מכולות|holdings?|investments?|properties|property|real estate|construction|consulting|consultancy|management|software|technolog(?:y|ies)|equipment|machines?|suppl(?:y|ies|iers?)|imports?|exports?|wholesale|distribution|distributors?|manufactur(?:e|er|ers|ing)|packaging|marketing|roast(?:er|ers|ing)|transport|containers?';

    public static function classify(string $name, string $englishName = '', string $purpose = '', string $description = ''): ?string
    {
        $names = self::normalize($name.' '.$englishName);
        $details = self::normalize($purpose.' '.$description);
        if (self::matches($names.' '.$details, '(?:ו)?(?:ה)?(?:'.self::INDIRECT_ACTIVITY.')')) {
            return null;
        }

        // Generic legal purposes have no activity signal and therefore cannot assign a category.
        $categories = self::categories($names);
        if ($categories === []) {
            $categories = self::categories($details);
        }
        // A pizzeria may also explicitly call itself a restaurant; use the specific activity.
        if (count($categories) === 2
            && in_array('professionals.fast_food', $categories, true)
            && in_array('food_catering.restaurants', $categories, true)) {
            return 'professionals.fast_food';
        }

        return count($categories) === 1 ? $categories[0] : null;
    }

    private static function categories(string $value): array
    {
        return array_keys(array_filter(self::PATTERNS, static fn (string $pattern): bool => self::matches($value, $pattern)));
    }

    private static function matches(string $value, string $pattern): bool
    {
        return preg_match('/(?:^| )(?:ו)?(?:ה)?('.$pattern.')(?: |$)/u', $value) === 1;
    }

    private static function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $value = str_replace(['é', 'É', '&'], ['e', 'e', ' and '], $value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $value) ?? $value;

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
