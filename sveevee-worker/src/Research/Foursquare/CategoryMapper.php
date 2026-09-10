<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Foursquare;

use Sveevee\Worker\Research\SourceCatalogMetadata;

/** Explicit category meanings; unknown source categories remain available for catalog review. */
final class CategoryMapper
{
    private const LABELS = [
        'bakery' => 'food_catering.bakery', 'restaurant' => 'food_catering.restaurants',
        'fast food restaurant' => 'professionals.fast_food', 'café' => 'food_catering.cafes',
        'cafe' => 'food_catering.cafes', 'coffee shop' => 'food_catering.cafes',
        'caterer' => 'professionals.catering', 'grocery store' => 'professionals.grocery_food',
        'supermarket' => 'professionals.grocery_food', 'butcher' => 'food_catering.meat_deli',
        'deli' => 'food_catering.meat_deli', 'bar' => 'food_catering.bars',
        'pub' => 'food_catering.bars', 'hotel' => 'travel_leisure.hotels_guesthouses',
        'bed and breakfast' => 'travel_leisure.hotels_guesthouses',
        'event space' => 'professionals.venues', 'wedding hall' => 'professionals.venues',
        'pharmacy' => 'health_care.pharmacies', 'medical center' => 'health_care.clinics_doctors',
        "doctor's office" => 'health_care.clinics_doctors',
        'physical therapy clinic' => 'health_care.physiotherapy',
        'mental health service' => 'health_care.therapy_counseling',
        'hair salon' => 'professionals.hair_salons', 'barbershop' => 'professionals.hair_salons',
        'nail salon' => 'beauty_personal_care.nails', 'spa' => 'beauty_personal_care.spa_massage',
        'beauty salon' => 'professionals.beauty_salons',
        'clothing store' => 'shopping_retail.fashion', 'shoe store' => 'shopping_retail.shoes_bags',
        'jewelry store' => 'shopping_retail.jewelry_watches', 'florist' => 'shopping_retail.gifts_flowers',
        'gift store' => 'shopping_retail.gifts_flowers', 'bookstore' => 'shopping_retail.books_stationery',
        'stationery store' => 'shopping_retail.books_stationery', 'pet store' => 'shopping_retail.pet_stores',
        'furniture store' => 'shopping_retail.furniture_home_decor',
        'appliance store' => 'shopping_retail.appliances', 'toy store' => 'shopping_retail.baby_kids',
        'plumber' => 'services.home_repairs.plumbing', 'electrician' => 'professionals.electricians',
        'locksmith' => 'services.home_repairs.locksmith', 'carpenter' => 'services.home_repairs.carpentry',
        'contractor' => 'professionals.building_contractors', 'interior designer' => 'professionals.interior_design',
        'pest control service' => 'professionals.pest_control', 'moving company' => 'professionals.moving',
        'driving school' => 'education_courses.driving_lessons',
        'daycare' => 'education_courses.daycare_kindergarten',
        'preschool' => 'education_courses.daycare_kindergarten',
        'music school' => 'professionals.music_lessons', 'language school' => 'professionals.language_lessons',
        'photographer' => 'creators.photographer', 'car rental' => 'professionals.car_rental',
        'automotive repair shop' => 'professionals.garages', 'auto repair' => 'professionals.garages',
        'accounting and bookkeeping service' => 'legal_finance_business.accounting',
        'insurance agency' => 'legal_finance_business.insurance',
        'real estate agency' => 'real_estate.real_estate_agents',
    ];

    public static function map(?string $label): ?string
    {
        foreach (array_reverse(explode('>', $label ?? '')) as $part) {
            $key = mb_strtolower(trim($part), 'UTF-8');
            if (isset(self::LABELS[$key])) {
                return self::LABELS[$key];
            }
        }

        return null;
    }

    public static function descriptors(array $row): array
    {
        $ids = is_array($row['fsq_category_ids'] ?? null) ? $row['fsq_category_ids'] : [];
        $labels = is_array($row['fsq_category_labels'] ?? null) ? $row['fsq_category_labels'] : [];
        $categories = [];
        foreach (array_unique([...array_keys($ids), ...array_keys($labels)]) as $index) {
            $id = SourceCatalogMetadata::text($ids[$index] ?? null, 255);
            $label = SourceCatalogMetadata::text($labels[$index] ?? null);
            if ($id === null && $label === null) {
                continue;
            }
            $key = $id ?? 'label:'.hash('sha256', (string) $labels[$index]);
            $categories[$key] = ['key' => $key, 'label' => $label ?? $id,
                'catalog_key' => self::map($label)];
        }

        return array_values($categories);
    }
}
