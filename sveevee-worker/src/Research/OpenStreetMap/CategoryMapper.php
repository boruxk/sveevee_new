<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\OpenStreetMap;

/** Only direct semantic equivalents; every unmapped tag remains in source_categories. */
final class CategoryMapper
{
    private const MAP = [
        'shop=bakery' => 'food_catering.bakery', 'amenity=restaurant' => 'food_catering.restaurants',
        'amenity=cafe' => 'food_catering.cafes', 'amenity=fast_food' => 'professionals.fast_food',
        'amenity=bar' => 'food_catering.bars', 'amenity=pub' => 'food_catering.bars',
        'shop=supermarket' => 'professionals.grocery_food', 'shop=convenience' => 'professionals.grocery_food',
        'shop=butcher' => 'food_catering.meat_deli', 'shop=deli' => 'food_catering.meat_deli',
        'tourism=hotel' => 'travel_leisure.hotels_guesthouses', 'tourism=guest_house' => 'travel_leisure.hotels_guesthouses',
        'tourism=hostel' => 'travel_leisure.hotels_guesthouses', 'tourism=motel' => 'travel_leisure.hotels_guesthouses',
        'amenity=pharmacy' => 'health_care.pharmacies', 'amenity=clinic' => 'health_care.clinics_doctors',
        'amenity=doctors' => 'health_care.clinics_doctors', 'healthcare=clinic' => 'health_care.clinics_doctors',
        'healthcare=doctor' => 'health_care.clinics_doctors', 'healthcare=physiotherapist' => 'health_care.physiotherapy',
        'shop=hairdresser' => 'professionals.hair_salons', 'shop=beauty' => 'professionals.beauty_salons',
        'shop=clothes' => 'shopping_retail.fashion', 'shop=shoes' => 'shopping_retail.shoes_bags',
        'shop=jewelry' => 'shopping_retail.jewelry_watches', 'shop=florist' => 'shopping_retail.gifts_flowers',
        'shop=gift' => 'shopping_retail.gifts_flowers', 'shop=books' => 'shopping_retail.books_stationery',
        'shop=stationery' => 'shopping_retail.books_stationery', 'shop=pet' => 'shopping_retail.pet_stores',
        'shop=furniture' => 'shopping_retail.furniture_home_decor', 'shop=car_repair' => 'professionals.garages',
        'shop=estate_agent' => 'real_estate.real_estate_agents', 'office=estate_agent' => 'real_estate.real_estate_agents',
        'office=insurance' => 'legal_finance_business.insurance', 'office=accountant' => 'legal_finance_business.accounting',
        'craft=electrician' => 'professionals.electricians', 'craft=plumber' => 'services.home_repairs.plumbing',
        'craft=locksmith' => 'services.home_repairs.locksmith', 'craft=carpenter' => 'services.home_repairs.carpentry',
        'craft=photographer' => 'creators.photographer', 'amenity=car_rental' => 'professionals.car_rental',
        'amenity=driving_school' => 'education_courses.driving_lessons',
        'amenity=kindergarten' => 'education_courses.daycare_kindergarten', 'amenity=childcare' => 'education_courses.daycare_kindergarten',
        'amenity=music_school' => 'professionals.music_lessons', 'amenity=language_school' => 'professionals.language_lessons',
    ];

    public static function map(string $tag): ?string
    {
        return self::MAP[$tag] ?? null;
    }
}
