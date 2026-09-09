<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Overture;

/** Exact source categories and explicit ancestors; unknown places remain uncategorized. */
final class TaxonomyMapper
{
    /** Specific meanings take precedence over broader source ancestors. */
    private const PRIMARY = [
        'electrician' => 'professionals.electricians',
        'plumbing' => 'services.home_repairs.plumbing',
        'handyman' => 'services.home_repairs.handyman',
        'building_contractor' => 'professionals.building_contractors',
        'general_contractor' => 'professionals.building_contractors',
        'interior_design' => 'professionals.interior_design',
        'heating_and_air_conditioning' => 'services.home_repairs.air_conditioning',
        'air_conditioning_service' => 'services.home_repairs.air_conditioning',
        'painting' => 'services.home_repairs.painting',
        'drywall_installation_and_repair' => 'professionals.drywall',
        'roofing' => 'professionals.sealing_roofing',
        'waterproofing' => 'professionals.sealing_roofing',
        'carpenter' => 'services.home_repairs.carpentry',
        'window_installation' => 'services.home_repairs.windows_shutters',
        'key_and_locksmith' => 'services.home_repairs.locksmith',
        'pest_control_service' => 'professionals.pest_control',
        'landscaping' => 'services.home_repairs.gardening',
        'gardener' => 'services.home_repairs.gardening',
        'home_cleaning' => 'professionals.cleaning_polish',
        'office_cleaning' => 'professionals.cleaning_polish',
        'carpet_cleaning' => 'professionals.cleaning_polish',
        'window_washing' => 'professionals.cleaning_polish',
        'movers' => 'professionals.moving',
        'moving_company' => 'professionals.moving',
        'security_system' => 'professionals.security',
        'security_service' => 'professionals.security',
        'septic_service' => 'professionals.sewage_contractors',
        'junk_removal_and_hauling' => 'professionals.waste_recycling',
        'recycling_center' => 'professionals.waste_recycling',
        'industrial_design' => 'professionals.industrial_design',
        'clothing_store' => 'shopping_retail.fashion',
        'shoe_store' => 'shopping_retail.shoes_bags',
        'luggage_store' => 'shopping_retail.shoes_bags',
        'jewelry_store' => 'shopping_retail.jewelry_watches',
        'watch_store' => 'shopping_retail.jewelry_watches',
        'florist' => 'shopping_retail.gifts_flowers',
        'gift_shop' => 'shopping_retail.gifts_flowers',
        'furniture_store' => 'shopping_retail.furniture_home_decor',
        'home_decor' => 'shopping_retail.furniture_home_decor',
        'carpet_store' => 'shopping_retail.furniture_home_decor',
        'kitchen_and_bath' => 'shopping_retail.kitchen_home_goods',
        'kitchen_supply_store' => 'shopping_retail.kitchen_home_goods',
        'appliance_store' => 'shopping_retail.appliances',
        'bookstore' => 'shopping_retail.books_stationery',
        'comic_books_store' => 'shopping_retail.books_stationery',
        'stationery_store' => 'shopping_retail.books_stationery',
        'office_supply_store' => 'shopping_retail.books_stationery',
        'toys_and_games_store' => 'shopping_retail.baby_kids',
        'baby_gear_and_furniture' => 'shopping_retail.baby_kids',
        'pet_store' => 'shopping_retail.pet_stores',
        'aquatic_pet_store' => 'shopping_retail.pet_stores',
        'hair_salon' => 'professionals.hair_salons',
        'hair_stylist' => 'professionals.hair_salons',
        'barber' => 'professionals.hair_salons',
        'hair_extensions' => 'professionals.hair_salons',
        'beauty_salon' => 'professionals.beauty_salons',
        'nail_salon' => 'beauty_personal_care.nails',
        'makeup_artist' => 'beauty_personal_care.makeup',
        'skin_care_and_makeup' => 'professionals.cosmetics',
        'laser_hair_removal' => 'professionals.beauticians',
        'hair_removal' => 'professionals.beauticians',
        'spa' => 'beauty_personal_care.spa_massage',
        'massage_therapy' => 'beauty_personal_care.spa_massage',
        'fitness_trainer' => 'professionals.personal_trainer',
        'nutritionist' => 'professionals.nutrition',
        'dietitian' => 'professionals.nutrition',
        'pharmacy' => 'health_care.pharmacies',
        'doctors_office' => 'health_care.clinics_doctors',
        'physical_therapy' => 'health_care.physiotherapy',
        'psychotherapy' => 'health_care.therapy_counseling',
        'psychologist' => 'health_care.therapy_counseling',
        'counseling_and_mental_health' => 'health_care.therapy_counseling',
        'medical_supply_store' => 'health_care.medical_equipment',
        'dental_supply_store' => 'health_care.medical_equipment',
        'home_health_care' => 'health_care.caregivers_nursing',
        'nursing_home' => 'health_care.senior_care',
        'assisted_living_facility' => 'health_care.senior_care',
        'tutoring_service' => 'professionals.private_tutors',
        'language_school' => 'professionals.language_lessons',
        'music_school' => 'professionals.music_lessons',
        'driving_school' => 'education_courses.driving_lessons',
        'preschool' => 'education_courses.daycare_kindergarten',
        'day_care_preschool' => 'education_courses.daycare_kindergarten',
        'child_care_and_day_care' => 'education_courses.daycare_kindergarten',
        'vocational_and_technical_school' => 'education_courses.professional_training',
        'religious_school' => 'education_courses.religious_studies',
        'cooking_school' => 'education_courses.courses_workshops',
        'art_school' => 'education_courses.courses_workshops',
        'party_and_event_planning' => 'professionals.event_production',
        'event_photography_service' => 'professionals.photo_video',
        'session_photography_service' => 'creators.photographer',
        'photographer' => 'creators.photographer',
        'dj' => 'entertainers.dj',
        'party_equipment_rental' => 'events_entertainment.party_equipment',
        'automotive_repair' => 'professionals.garages',
        'auto_body_shop' => 'professionals.garages',
        'tire_dealer_and_repair' => 'professionals.garages',
        'motorcycle_repair' => 'professionals.garages',
        'truck_repair' => 'professionals.garages',
        'car_rental_service' => 'professionals.car_rental',
        'towing_service' => 'auto_transport.towing_roadside',
        'bike_repair_maintenance' => 'auto_transport.bikes_scooters',
        'bike_rental' => 'auto_transport.bikes_scooters',
        'courier_and_delivery_service' => 'professionals.courier',
        'food_delivery_service' => 'professionals.courier',
        'taxi_service' => 'professionals.shuttles',
        'accountant' => 'legal_finance_business.accounting',
        'bookkeeper' => 'legal_finance_business.accounting',
        'tax_service' => 'legal_finance_business.accounting',
        'insurance_agency' => 'legal_finance_business.insurance',
        'health_insurance_office' => 'legal_finance_business.insurance',
        'real_estate_agent' => 'real_estate.real_estate_agents',
        'real_estate_agency' => 'real_estate.real_estate_agents',
        'business_consulting' => 'legal_finance_business.business_consulting',
        'marketing_agency' => 'professional_business.marketing_seo',
        'internet_marketing_service' => 'professional_business.marketing_seo',
        'b2b_marketing_consultant' => 'professional_business.marketing_seo',
        'web_designer' => 'professional_business.web_it',
        'web_hosting_service' => 'professional_business.web_it',
        'software_development' => 'professional_business.web_it',
        'it_service_and_computer_repair' => 'professionals.computer_technician',
        'appliance_repair_service' => 'professionals.appliance_technician',
        'translation_service' => 'professionals.translation',
        'graphic_design' => 'creators.graphic_designer',
        'veterinarian' => 'professionals.veterinarians',
        'pet_groomer' => 'professionals.pet_grooming',
        'dog_trainer' => 'professionals.dog_training',
        'dog_walker' => 'pets.pet_sitting_walking',
        'pet_sitting' => 'pets.pet_sitting_walking',
        'pet_boarding' => 'pets.pet_sitting_walking',
        'travel_agent' => 'travel_transportation.travel_services',
        'tour_operator' => 'travel_transportation.trips_tours',
        'tour_guide' => 'travel_transportation.trips_tours',
        'bus_service' => 'travel_transportation.bus_services',
    ];

    /** These families have a corresponding catalog meaning; generic services/places do not. */
    private const FAMILIES = [
        'attorney_or_law_firm' => 'legal_finance_business.lawyers',
        'financial_service' => 'legal_finance_business.financial_services',
        'dental_clinic' => 'health_care.dentists',
        'outpatient_care_facility' => 'health_care.clinics_doctors',
        'vision_or_eye_care_clinic' => 'health_care.clinics_doctors',
        'pediatric_clinic' => 'health_care.clinics_doctors',
        'behavioral_or_mental_health_clinic' => 'health_care.therapy_counseling',
        'complementary_and_alternative_medicine' => 'professionals.alternative_medicine',
        'pharmacy_and_drug_store' => 'health_care.pharmacies',
        'fashion_and_apparel_store' => 'shopping_retail.fashion',
        'flowers_and_gifts_store' => 'shopping_retail.gifts_flowers',
        'electronics_store' => 'shopping_retail.electronics',
        'sporting_goods_store' => 'shopping_retail.sports_outdoor',
        'vehicle_parts_store' => 'professionals.car_accessories',
        'b2b_advertising_and_marketing_service' => 'professional_business.marketing_seo',
    ];

    public static function map(array $taxonomy, bool $allPlaces = false): ?string
    {
        $primary = is_string($taxonomy['primary'] ?? null) ? $taxonomy['primary'] : '';
        $hierarchy = is_array($taxonomy['hierarchy'] ?? null) ? $taxonomy['hierarchy'] : [];
        $has = static fn (string $value): bool => $primary === $value || in_array($value, $hierarchy, true);
        if ($has('private_lodging') || $primary === 'cafeteria') {
            return null;
        }
        // Preserve previous category choices, including food trucks and narrower deli/bar matches.
        $legacy = match (true) {
            $has('bakery'), in_array($primary, ['cupcake_shop', 'donut_shop', 'bagel_shop'], true) => 'food_catering.bakery',
            in_array($primary, ['fast_food_restaurant', 'pizza_restaurant', 'burger_restaurant', 'falafel_restaurant', 'doner_kebab_restaurant', 'hot_dog_restaurant', 'fish_and_chips_restaurant', 'sandwich_shop', 'food_truck_stand'], true) => 'professionals.fast_food',
            $has('cafe'), $has('coffee_shop') => 'food_catering.cafes',
            $primary === 'caterer' => 'professionals.catering',
            in_array($primary, ['butcher_shop', 'delicatessen'], true) => 'food_catering.meat_deli',
            $has('grocery_store'), in_array($primary, ['convenience_store', 'supermarket', 'produce_store', 'health_food_store'], true) => 'professionals.grocery_food',
            $has('bar'), in_array($primary, ['beer_garden', 'gastropub', 'tapas_bar'], true) => 'food_catering.bars',
            in_array($primary, ['event_venue', 'exhibition_and_trade_fair_venue'], true) => 'professionals.venues',
            $has('hotel'), $has('resort'), in_array($primary, ['hostel', 'bed_and_breakfast', 'inn', 'lodge', 'service_apartment'], true) => 'travel_leisure.hotels_guesthouses',
            $has('restaurant'), in_array($primary, ['diner', 'bistro', 'fondue_restaurant'], true) => 'food_catering.restaurants',
            default => null,
        };
        if ($legacy !== null || ! $allPlaces) {
            return $legacy;
        }
        foreach ([$primary, ...array_reverse($hierarchy)] as $category) {
            if (is_string($category) && isset(self::PRIMARY[$category])) {
                return self::PRIMARY[$category];
            }
            if (is_string($category) && isset(self::FAMILIES[$category])) {
                return self::FAMILIES[$category];
            }
        }

        return null;
    }
}
