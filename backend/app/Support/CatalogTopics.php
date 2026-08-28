<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogTopics
{
    public const SCOPE_BUSINESS_PAGES = 'business_pages';
    public const SCOPE_COMMUNITY_PAGES = 'community_pages';
    public const SCOPE_ADS = 'ads';
    public const SCOPE_USERS = 'users';
    public const SCOPE_PRODUCTS = 'products';
    public const SCOPE_SERVICES = 'services';
    public const SCOPE_EVENTS = 'events';

    public const ALL_SCOPES = [
        self::SCOPE_BUSINESS_PAGES,
        self::SCOPE_COMMUNITY_PAGES,
        self::SCOPE_ADS,
        self::SCOPE_USERS,
        self::SCOPE_PRODUCTS,
        self::SCOPE_SERVICES,
        self::SCOPE_EVENTS,
    ];

    public const POPULAR_KEYS = [
        'professionals.electricians',
        'professionals.renovation',
        'food_catering.restaurants',
        'products.home_garden.furniture',
        'products.electronics_computers.phones_tablets',
        'services.home_repairs.plumbing',
        'services.beauty_wellness.hair',
        'events.community_social.community_festival',
        'events.kids_family.kids_show',
        'community_pages.local.neighborhood_group',
        'legal_finance_business.lawyers',
        'education_courses.private_lessons',
    ];

    private static ?array $groups = null;

    private const AD_TOPIC_MAP = [
        'home_professionals.electrician' => 'professionals.electricians',
        'home_professionals.renovation' => 'professionals.renovation',
        'home_professionals.moving' => 'professionals.moving',
        'home_professionals.pest_control' => 'professionals.pest_control',
        'home_professionals.cleaning' => 'professionals.cleaning_polish',
        'home_professionals.handyman' => 'services.home_repairs.handyman',
        'home_professionals.plumbing' => 'services.home_repairs.plumbing',
        'home_professionals.air_conditioning' => 'services.home_repairs.air_conditioning',
        'home_professionals.painting' => 'services.home_repairs.painting',
        'home_professionals.locksmith' => 'services.home_repairs.locksmith',
        'home_professionals.windows_shutters' => 'services.home_repairs.windows_shutters',
        'home_professionals.carpentry' => 'services.home_repairs.carpentry',
        'home_professionals.pergolas' => 'professionals.renovation',
        'home_professionals.gardening' => 'services.home_repairs.gardening',
        'food_catering.restaurants' => 'food_catering.restaurants',
        'food_catering.catering' => 'professionals.catering',
        'food_catering.pizza_fast_food' => 'professionals.fast_food',
        'food_catering.meat_butcher' => 'products.food_grocery.meat_fish_deli',
        'food_catering.fish' => 'professionals.fish_restaurants',
        'food_catering.grocery' => 'professionals.grocery_food',
        'food_catering.food_for_events' => 'professionals.catering',
        'fashion.mens_fashion' => 'products.fashion_beauty.men_clothing',
        'fashion.womens_fashion' => 'products.fashion_beauty.women_clothing',
        'fashion.childrens_fashion' => 'products.fashion_beauty.kids_clothing',
        'fashion.suits_shirts' => 'products.fashion_beauty.men_clothing',
        'fashion.dresses' => 'products.fashion_beauty.women_clothing',
        'fashion.bridal_wedding_dresses' => 'products.fashion_beauty.women_clothing',
        'fashion.wigs_head_coverings' => 'products.fashion_beauty.wigs_head_coverings',
        'beauty_personal_care.hairdresser' => 'professionals.hair_salons',
        'beauty_personal_care.hair_treatments' => 'professionals.hair_salons',
        'beauty_personal_care.skin_care' => 'products.fashion_beauty.cosmetics_skin_care',
        'beauty_personal_care.hair_removal' => 'professionals.beauticians',
        'health_wellness.therapy' => 'health_care.therapy_counseling',
        'health_wellness.alternative_medicine' => 'professionals.alternative_medicine',
        'health_wellness.massage' => 'beauty_personal_care.spa_massage',
        'health_wellness.orthopedics_orthotics' => 'health_care.medical_equipment',
        'health_wellness.opticians' => 'health_care.clinics_doctors',
        'health_wellness.memory_senior_services' => 'health_care.senior_care',
        'health_wellness.personal_care' => 'health_care.caregivers_nursing',
        'kids_family.daycare' => 'education_courses.daycare_kindergarten',
        'kids_family.babysitting' => 'health_care.caregivers_nursing',
        'events_entertainment.music_dj' => 'entertainers.dj',
        'events_entertainment.event_photography' => 'professionals.photo_video',
        'events_entertainment.video' => 'creators.video_editor',
        'events_entertainment.event_equipment' => 'services.events_entertainment.party_equipment',
        'events_entertainment.attractions' => 'entertainers.event_attractions',
        'events_entertainment.games' => 'services.events_entertainment.attractions',
        'events_entertainment.event_venues' => 'professionals.venues',
        'events_entertainment.party_rentals' => 'services.events_entertainment.party_equipment',
        'kids_family.childrens_activities' => 'professionals.kids_activities',
        'kids_family.toys_games' => 'products.kids_baby.toys_games',
        'kids_family.inflatables' => 'services.events_entertainment.attractions',
        'kids_family.camps' => 'events.kids_family.camps',
        'kids_family.parenting_family_services' => 'events.kids_family.parenting_event',
        'education_courses.schools' => 'education_courses.courses_workshops',
        'education_courses.private_lessons' => 'professionals.private_tutors',
        'education_courses.tutoring' => 'professionals.private_tutors',
        'education_courses.courses_workshops' => 'education_courses.courses_workshops',
        'education_courses.religious_studies' => 'events.religious_jewish.shiur',
        'shopping_retail.general_retail' => 'shopping_retail.sales_special_offers',
        'electronics_appliances.home_appliances' => 'shopping_retail.appliances',
        'electronics_appliances.mobile_phones' => 'products.electronics_computers.phones_tablets',
        'electronics_appliances.computers' => 'products.electronics_computers.computers_laptops',
        'electronics_appliances.computer_repair' => 'professionals.computer_technician',
        'electronics_appliances.electrical_products' => 'shopping_retail.electronics',
        'electronics_appliances.small_appliances' => 'products.appliances.coffee_small_appliances',
        'community_religious.community_events' => 'events.community_social.community_festival',
        'beauty_personal_care.cosmetics' => 'products.fashion_beauty.cosmetics_skin_care',
    ];

    private const MARKET_PRODUCT_TYPES = [
        [
            'key' => 'market.furniture',
            'slug' => 'furniture',
            'labels' => [
                'he' => 'ריהוט',
                'en' => 'Furniture',
                'ru' => 'Мебель',
                'fr' => 'Meubles',
            ],
            'color' => '#0891b2',
            'topic_keys' => [
                'products.home_garden.furniture',
            ],
        ],
        [
            'key' => 'market.bicycles',
            'slug' => 'bicycles',
            'labels' => [
                'he' => 'אופניים',
                'en' => 'Bicycles',
                'ru' => 'Велосипеды',
                'fr' => 'Vélos',
            ],
            'color' => '#0284c7',
            'topic_keys' => [
                'products.sports_leisure.bikes_scooters',
                'products.cars_accessories.bike_scooter_parts',
            ],
        ],
        [
            'key' => 'market.electronics',
            'slug' => 'electronics',
            'labels' => [
                'he' => 'מוצרי חשמל',
                'en' => 'Electronics',
                'ru' => 'Электроника',
                'fr' => 'Électronique',
            ],
            'color' => '#475569',
            'topic_keys' => [
                'products.electronics_computers.phones_tablets',
                'products.electronics_computers.computers_laptops',
                'products.electronics_computers.tv_audio',
                'products.electronics_computers.cameras',
                'products.electronics_computers.gaming',
                'products.electronics_computers.smart_home_security',
                'products.electronics_computers.accessories_cables',
                'products.electronics_computers.printers_office_tech',
                'products.appliances.refrigerators_freezers',
                'products.appliances.ovens_stoves',
                'products.appliances.dishwashers',
                'products.appliances.laundry',
                'products.appliances.heating_cooling',
                'products.appliances.coffee_small_appliances',
                'products.appliances.vacuum_cleaning_devices',
            ],
        ],
        [
            'key' => 'market.kids',
            'slug' => 'kids',
            'labels' => [
                'he' => 'מוצרים לילדים',
                'en' => 'Kids products',
                'ru' => 'Детские товары',
                'fr' => 'Produits pour enfants',
            ],
            'color' => '#f59e0b',
            'topic_keys' => [
                'products.kids_baby.strollers_car_seats',
                'products.kids_baby.baby_furniture',
                'products.kids_baby.toys_games',
                'products.kids_baby.school_supplies',
                'products.kids_baby.baby_clothing',
                'products.kids_baby.feeding_care',
                'products.fashion_beauty.kids_clothing',
            ],
        ],
    ];

    public static function groups(): array
    {
        return collect(self::rawGroups())
            ->map(fn (array $group) => self::groupPayload($group))
            ->values()
            ->all();
    }

    public static function all(): Collection
    {
        return collect(self::rawGroups())
            ->flatMap(function (array $group): array {
                return collect($group['topics'])
                    ->map(fn (array $topic) => self::topicPayload($topic, $group))
                    ->all();
            })
            ->values();
    }

    public static function publicPayload(array|string|null $scopes = null, ?array $popularKeys = null): array
    {
        $normalizedScopes = self::normalizeScopes($scopes);
        $groups = $normalizedScopes === []
            ? self::groups()
            : self::scopedOptionsForScopes($normalizedScopes);

        return [
            'groups' => $groups,
            'popular_topics' => collect($popularKeys ?? self::POPULAR_KEYS)
                ->map(fn (string $key) => self::findByKey($key))
                ->filter(fn (?array $topic) => $topic && (
                    $normalizedScopes === [] || self::topicHasAnyScope($topic, $normalizedScopes)
                ))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public static function scopeHubs(): array
    {
        return [
            self::scopeHub(
                'businesses',
                [self::SCOPE_BUSINESS_PAGES],
                'עסקים',
                'Businesses',
                'Бизнесы',
                'Entreprises',
                'קטלוג עסקים מקומיים לפי תחום, עיר ושכונה.',
                'Browse local businesses by category, city, and neighborhood.',
                'Каталог местных бизнесов по категории, городу и району.',
                'Parcourez les entreprises locales par catégorie, ville et quartier.'
            ),
            self::scopeHub(
                'communities',
                [self::SCOPE_COMMUNITY_PAGES],
                'קהילות',
                'Communities',
                'Сообщества',
                'Communautés',
                'עמודי קהילה מקומיים, קבוצות, עזרה ואירועים שכונתיים.',
                'Find local community pages, groups, help, and neighborhood activity.',
                'Найдите местные сообщества, группы, помощь и районные события.',
                'Trouvez des pages communautaires locales, des groupes, de l’aide et des activités de quartier.'
            ),
            self::scopeHub(
                'products',
                [self::SCOPE_PRODUCTS],
                'מוצרים',
                'Products',
                'Товары',
                'Produits',
                'מוצרים מחנויות ועסקים מקומיים לפי קטגוריה ואזור.',
                'Browse products from local stores and businesses by category and area.',
                'Товары местных магазинов и бизнесов по категории и району.',
                'Parcourez les produits des commerces locaux par catégorie et zone.'
            ),
            self::scopeHub(
                'services',
                [self::SCOPE_SERVICES],
                'שירותים',
                'Services',
                'Услуги',
                'Services',
                'שירותים ובעלי מקצוע מקומיים לפי תחום ואזור.',
                'Find local services and professionals by category and area.',
                'Местные услуги и специалисты по категории и району.',
                'Trouvez des services et des professionnels locaux par catégorie et zone.'
            ),
            self::scopeHub(
                'events',
                [self::SCOPE_EVENTS],
                'אירועים',
                'Events',
                'События',
                'Événements',
                'אירועים מקומיים, סדנאות, מופעים ופעילות קהילתית.',
                'Discover local events, workshops, shows, and community activity.',
                'Местные события, мастер-классы, выступления и активности.',
                'Découvrez les événements locaux, les ateliers, les spectacles et les activités.'
            ),
            self::scopeHub(
                'ads',
                [self::SCOPE_ADS],
                'מודעות',
                'Ads',
                'Объявления',
                'Annonces',
                'מודעות מקומיות חינמיות לפי תחום ואזור.',
                'Browse free local ads by category and area.',
                'Бесплатные местные объявления по категории и району.',
                'Parcourez les annonces locales gratuites par catégorie et zone.'
            ),
            self::scopeHub(
                'people',
                [self::SCOPE_USERS],
                'אנשים',
                'People',
                'Люди',
                'Personnes',
                'אנשי מקצוע ויוצרים מקומיים לפי תחום ואזור.',
                'Find local professionals, entertainers, and creators by category and area.',
                'Местные специалисты, артисты и авторы по категории и району.',
                'Trouvez des professionnels, des artistes et des créateurs locaux par catégorie et zone.'
            ),
        ];
    }

    public static function findScopeHub(?string $slug): ?array
    {
        if (! filled($slug)) {
            return null;
        }

        return collect(self::scopeHubs())->firstWhere('slug', $slug);
    }

    public static function findByKey(?string $key): ?array
    {
        if (! filled($key)) {
            return null;
        }

        return self::all()->first(function (array $topic) use ($key): bool {
            return $topic['key'] === $key || in_array($key, $topic['aliases'], true);
        });
    }

    public static function findBySlug(?string $slug): ?array
    {
        if (! filled($slug)) {
            return null;
        }

        return self::all()->first(function (array $topic) use ($slug): bool {
            return $topic['slug'] === $slug || in_array($slug, $topic['aliases'], true);
        });
    }

    public static function keysForScope(string $scope): array
    {
        return self::all()
            ->filter(fn (array $topic) => in_array($scope, $topic['scopes'], true))
            ->pluck('key')
            ->values()
            ->all();
    }

    public static function keysForTopic(string $topicKey): array
    {
        $topic = self::findByKey($topicKey);

        return $topic ? collect([$topic['key'], ...$topic['aliases']])->unique()->values()->all() : [$topicKey];
    }

    public static function canonicalKeyForScope(?string $key, string $scope): ?string
    {
        $topic = self::findByKey($key);

        if (! $topic || ! in_array($scope, $topic['scopes'], true)) {
            return null;
        }

        return $topic['key'];
    }

    public static function hasScope(?string $key, string $scope): bool
    {
        $topic = self::findByKey($key);

        return $topic !== null && in_array($scope, $topic['scopes'], true);
    }

    public static function keyForAdCategory(?string $category): ?string
    {
        if (! filled($category)) {
            return null;
        }

        $mapped = self::AD_TOPIC_MAP[$category] ?? $category;
        $topic = self::findByKey($mapped);

        return $topic ? $topic['key'] : null;
    }

    public static function adCategoriesForTopic(string $topicKey): array
    {
        $topic = self::findByKey($topicKey);
        $topicKeys = self::keysForTopic($topicKey);
        $categories = ($topic && self::topicSupportsAds($topic) ? collect($topicKeys) : collect())
            ->merge(collect(self::AD_TOPIC_MAP)
            ->filter(fn (string $mappedKey) => in_array($mappedKey, $topicKeys, true))
            ->keys());

        foreach ($topicKeys as $key) {
            if (in_array($key, AdCategories::KEYS, true)) {
                $categories->push($key);
            }
        }

        return $categories->unique()->values()->all();
    }

    public static function isAdCategoryValue(?string $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        if (in_array($value, AdCategories::KEYS, true)) {
            return true;
        }

        $topic = self::findByKey($value) ?? self::findBySlug($value);

        if (! $topic) {
            return false;
        }

        return self::topicSupportsAds($topic);
    }

    public static function keyForUserType(?string $userType): ?string
    {
        if (! filled($userType)) {
            return null;
        }

        $topic = self::findByKey($userType);

        return $topic && in_array(self::SCOPE_USERS, $topic['scopes'], true) ? $topic['key'] : null;
    }

    public static function userTypesForTopic(string $topicKey): array
    {
        $topic = self::findByKey($topicKey);

        if (! $topic || ! in_array(self::SCOPE_USERS, $topic['scopes'], true)) {
            return [];
        }

        return collect([$topic['key'], ...$topic['aliases']])
            ->filter(fn (string $key) => in_array($key, UserTypes::KEYS, true))
            ->values()
            ->all();
    }

    public static function scopedOptions(string $scope): array
    {
        return self::scopedOptionsForScopes([$scope]);
    }

    public static function scopedOptionsForScopes(array|string|null $scopes): array
    {
        $normalizedScopes = self::normalizeScopes($scopes);

        return collect(self::groups())
            ->map(function (array $group) use ($normalizedScopes): ?array {
                $topics = collect($group['topics'])
                    ->filter(fn (array $topic) => $normalizedScopes === [] || self::topicHasAnyScope($topic, $normalizedScopes))
                    ->values()
                    ->all();

                if ($topics === []) {
                    return null;
                }

                return array_merge($group, ['topics' => $topics]);
            })
            ->filter()
            ->values()
            ->all();
    }

    public static function marketProductTypes(): array
    {
        return collect(self::MARKET_PRODUCT_TYPES)
            ->map(fn (array $type): array => self::marketProductTypePayload($type))
            ->values()
            ->all();
    }

    public static function findMarketProductType(?string $slug): ?array
    {
        if (! filled($slug)) {
            return null;
        }

        return collect(self::marketProductTypes())
            ->first(fn (array $type): bool => $type['slug'] === $slug);
    }

    public static function marketProductTypeForTopicKey(?string $topicKey): ?array
    {
        if (! filled($topicKey)) {
            return null;
        }

        return collect(self::marketProductTypes())
            ->first(fn (array $type): bool => in_array($topicKey, $type['topic_keys'], true));
    }

    public static function catalogPath(array|string $topic, ?string $city = null, ?string $neighborhood = null): string
    {
        $topicPayload = is_array($topic) ? $topic : self::findByKey($topic);
        $topicSlug = $topicPayload['slug'] ?? (string) $topic;

        $parts = ['catalog'];

        if (filled($city)) {
            $parts[] = self::locationSlug($city);
        }

        if (filled($city) && filled($neighborhood)) {
            $parts[] = self::locationSlug($neighborhood);
        }

        if (filled($topicSlug)) {
            $parts[] = $topicSlug;
        }

        return '/'.implode('/', $parts);
    }

    public static function marketPath(string $city, array|string|null $topic = null): string
    {
        $parts = ['market', self::locationSlug($city)];

        if (filled($topic)) {
            $topicPayload = is_array($topic) ? $topic : self::findByKey($topic);
            $topicSlug = $topicPayload['market_slug'] ?? $topicPayload['slug'] ?? (string) $topic;

            if (filled($topicSlug)) {
                $parts[] = $topicSlug;
            }
        }

        return '/'.implode('/', $parts);
    }

    public static function locationSlug(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $slug = Str::slug($value);

        return $slug !== '' ? $slug : null;
    }

    public static function resolveCitySlug(?string $slug): ?string
    {
        if (! filled($slug)) {
            return null;
        }

        return collect(config('locations.cities', []))
            ->pluck('name')
            ->first(fn (string $city) => self::locationSlug($city) === $slug);
    }

    public static function resolveNeighborhoodSlug(?string $city, ?string $slug): ?string
    {
        if (! filled($city) || ! filled($slug)) {
            return null;
        }

        $neighborhoods = collect(config('locations.cities', []))
            ->firstWhere('name', $city)['neighborhoods'] ?? [];

        return collect($neighborhoods)
            ->first(fn (string $neighborhood) => self::locationSlug($neighborhood) === $slug);
    }

    private static function normalizeScopes(array|string|null $scopes): array
    {
        return collect(Arr::wrap($scopes))
            ->filter(fn (?string $scope): bool => filled($scope) && in_array($scope, self::ALL_SCOPES, true))
            ->unique()
            ->values()
            ->all();
    }

    private static function topicHasAnyScope(array $topic, array $scopes): bool
    {
        return collect($scopes)->contains(function (string $scope) use ($topic): bool {
            if ($scope === self::SCOPE_ADS) {
                return in_array($scope, $topic['scopes'], true) || self::adCategoriesForTopic($topic['key']) !== [];
            }

            return in_array($scope, $topic['scopes'], true);
        });
    }

    private static function topicSupportsAds(array $topic): bool
    {
        $topicKeys = collect([$topic['key'], ...$topic['aliases']])->unique()->values();

        return in_array(self::SCOPE_ADS, $topic['scopes'], true)
            || collect(self::AD_TOPIC_MAP)->contains(fn (string $mappedKey) => $topicKeys->contains($mappedKey))
            || $topicKeys->contains(fn (string $key) => in_array($key, AdCategories::KEYS, true));
    }

    private static function rawGroups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $business = [self::SCOPE_BUSINESS_PAGES];
        $community = [self::SCOPE_COMMUNITY_PAGES];
        $products = [self::SCOPE_PRODUCTS];
        $services = [self::SCOPE_SERVICES];
        $events = [self::SCOPE_EVENTS];
        $ads = [self::SCOPE_ADS];
        $businessServices = [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES];
        $businessProducts = [self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS];
        $communityEvents = [self::SCOPE_COMMUNITY_PAGES, self::SCOPE_EVENTS];
        $userBusinessServices = [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES];

        self::$groups = [
            self::group('ads_real_estate', '#2563eb', $ads, [
                self::topic('real_estate.for_sale', 'real-estate-for-sale'),
                self::topic('real_estate.for_rent', 'real-estate-for-rent'),
                self::topic('real_estate.commercial', 'commercial-real-estate'),
                self::topic('real_estate.storage', 'storage-real-estate'),
                self::topic('real_estate.vacation_short_term_rental', 'vacation-short-term-rentals'),
            ]),
            self::group('ads_jobs', '#16a34a', $ads, [
                self::topic('jobs.job_offers', 'job-offers'),
                self::topic('jobs.job_seekers', 'job-seekers'),
                self::topic('jobs.office_administration', 'office-administration-jobs'),
                self::topic('jobs.sales', 'sales-jobs'),
                self::topic('jobs.education', 'education-jobs'),
                self::topic('jobs.childcare', 'childcare-jobs'),
                self::topic('jobs.cleaning', 'cleaning-jobs'),
                self::topic('jobs.caregiving', 'caregiving-jobs'),
                self::topic('jobs.part_time', 'part-time-jobs'),
            ]),
            self::group('home_renovation_building', '#f97316', $businessServices, [
                self::topic('professionals.electricians', 'electricians', [], $userBusinessServices),
                self::topic('services.home_repairs.plumbing', 'plumbing'),
                self::topic('services.home_repairs.handyman', 'handyman-services', ['services.home_repairs', 'home-repair-services']),
                self::topic('professionals.renovation', 'renovation', [], $userBusinessServices),
                self::topic('professionals.building_contractors', 'building-contractors', [], $userBusinessServices),
                self::topic('professionals.interior_design', 'interior-design', [], $userBusinessServices),
                self::topic('services.home_repairs.air_conditioning', 'air-conditioning'),
                self::topic('services.home_repairs.painting', 'painting'),
                self::topic('professionals.drywall', 'drywall', ['professionals.gypsum'], $userBusinessServices),
                self::topic('professionals.sealing_roofing', 'sealing-roofing', [], $userBusinessServices),
                self::topic('services.home_repairs.carpentry', 'carpentry'),
                self::topic('services.home_repairs.windows_shutters', 'windows-shutters'),
                self::topic('services.home_repairs.locksmith', 'locksmith'),
                self::topic('professionals.pest_control', 'pest-control', [], $userBusinessServices),
                self::topic('services.home_repairs.gardening', 'gardening-landscaping'),
                self::topic('professionals.cleaning_polish', 'cleaning-polish', [], $userBusinessServices),
                self::topic('professionals.moving', 'moving', [], $userBusinessServices),
                self::topic('professionals.security', 'security-smart-home', ['security'], $userBusinessServices),
                self::topic('professionals.sewage_contractors', 'sewage-contractors', [], $userBusinessServices),
                self::topic('professionals.waste_recycling', 'waste-recycling', [], $userBusinessServices),
                self::topic('professionals.industrial_design', 'industrial-design', [], $userBusinessServices),
                self::topic('professionals.prefab_building', 'prefab-building', [], $userBusinessServices),
            ]),
            self::group('food_hospitality', '#ef4444', $businessProducts, [
                self::topic('food_catering.restaurants', 'restaurants'),
                self::topic('food_catering.cafes', 'cafes'),
                self::topic('food_catering.bakery', 'bakery'),
                self::topic('professionals.catering', 'catering', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.fast_food', 'fast-food', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('food_catering.meat_deli', 'meat-deli'),
                self::topic('professionals.fish_restaurants', 'fish-restaurants', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.grocery_food', 'grocery-food', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('food_catering.prepared_food', 'prepared-food'),
                self::topic('food_catering.food_trucks', 'food-trucks'),
                self::topic('food_catering.kosher_specialty', 'kosher-specialty-food'),
                self::topic('food_catering.bars', 'bars'),
            ]),
            self::group('shopping_retail', '#0891b2', $businessProducts, [
                self::topic('shopping_retail.fashion', 'fashion-stores'),
                self::topic('shopping_retail.shoes_bags', 'shoes-bags'),
                self::topic('shopping_retail.jewelry_watches', 'jewelry-watches'),
                self::topic('shopping_retail.gifts_flowers', 'gifts-flowers', ['shopping_retail.gifts', 'gifts']),
                self::topic('shopping_retail.furniture_home_decor', 'furniture-home-decor', ['shopping_retail.furniture', 'furniture']),
                self::topic('shopping_retail.kitchen_home_goods', 'kitchen-home-goods', ['shopping_retail.kitchen', 'kitchen', 'shopping_retail.household_goods', 'household-goods']),
                self::topic('shopping_retail.electronics', 'electronics-stores'),
                self::topic('shopping_retail.appliances', 'appliance-stores'),
                self::topic('shopping_retail.books_stationery', 'books-stationery', ['shopping_retail.school_supplies', 'school-supplies']),
                self::topic('shopping_retail.baby_kids', 'baby-kids-stores'),
                self::topic('shopping_retail.sports_outdoor', 'sports-outdoor-stores'),
                self::topic('shopping_retail.pet_stores', 'pet-stores'),
                self::topic('shopping_retail.sales_special_offers', 'special-offers'),
            ]),
            self::group('beauty_wellness', '#e11d48', $businessServices, [
                self::topic('professionals.hair_salons', 'hair-salons', [], $userBusinessServices),
                self::topic('professionals.beauticians', 'beauticians', [], $userBusinessServices),
                self::topic('beauty_personal_care.nails', 'nails', [], [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS]),
                self::topic('beauty_personal_care.makeup', 'makeup', [], [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.cosmetics', 'cosmetics', [], $userBusinessServices),
                self::topic('professionals.beauty_salons', 'beauty-salons', [], $userBusinessServices),
                self::topic('beauty_personal_care.spa_massage', 'spa-massage'),
                self::topic('professionals.personal_trainer', 'personal-trainer', [], $userBusinessServices),
                self::topic('professionals.nutrition', 'nutrition', [], $userBusinessServices),
                self::topic('professionals.alternative_medicine', 'alternative-medicine', [], $userBusinessServices),
            ]),
            self::group('health_care', '#16a34a', $businessServices, [
                self::topic('health_care.dentists', 'dentists'),
                self::topic('health_care.clinics_doctors', 'clinics-doctors'),
                self::topic('health_care.physiotherapy', 'physiotherapy'),
                self::topic('health_care.therapy_counseling', 'therapy-counseling'),
                self::topic('health_care.senior_care', 'senior-care'),
                self::topic('health_care.caregivers_nursing', 'caregivers-nursing'),
                self::topic('health_care.pharmacies', 'pharmacies'),
                self::topic('health_care.medical_equipment', 'medical-equipment', [], [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.medical_massage', 'medical-massage', [], $userBusinessServices),
            ]),
            self::group('education_kids', '#7c3aed', [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS], [
                self::topic('professionals.private_tutors', 'private-tutors', ['education_courses.private_lessons'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES]),
                self::topic('education_courses.courses_workshops', 'courses-workshops'),
                self::topic('professionals.language_lessons', 'language-lessons', [], $userBusinessServices),
                self::topic('professionals.music_lessons', 'music-lessons', [], $userBusinessServices),
                self::topic('education_courses.driving_lessons', 'driving-lessons'),
                self::topic('education_courses.daycare_kindergarten', 'daycare-kindergarten'),
                self::topic('professionals.kids_activities', 'kids-activities', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('education_courses.professional_training', 'professional-training'),
                self::topic('education_courses.religious_studies', 'religious-studies'),
            ]),
            self::group('events_entertainment', '#f54291', [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS], [
                self::topic('professionals.event_production', 'event-production', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.dj', 'dj', ['events_entertainment.music_dj'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.musician_singer', 'musician-singer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('professionals.photo_video', 'photo-video', ['events_entertainment.event_photography'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES]),
                self::topic('professionals.venues', 'venues', ['events_entertainment.event_venues'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('events_entertainment.party_equipment', 'party-equipment'),
                self::topic('entertainers.kids_entertainer', 'kids-entertainer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.event_attractions', 'event-attractions', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('events_entertainment.flowers_decor', 'event-flowers-decor'),
                self::topic('entertainers.magician', 'magician', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.event_host', 'event-host', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.dancer_band', 'dancer-band', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.comedian', 'comedian', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.actor', 'actor', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.street_performer', 'street-performer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.karaoke', 'karaoke', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
            ]),
            self::group('auto_transport', '#0284c7', $businessServices, [
                self::topic('professionals.garages', 'garages', [], $userBusinessServices),
                self::topic('professionals.car_accessories', 'car-accessories', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.car_rental', 'car-rental', [], $userBusinessServices),
                self::topic('professionals.leasing', 'leasing', [], $userBusinessServices),
                self::topic('professionals.shuttles', 'shuttles-taxis', ['shuttles'], $userBusinessServices),
                self::topic('professionals.courier', 'courier-delivery', ['courier'], $userBusinessServices),
                self::topic('auto_transport.towing_roadside', 'towing-roadside'),
                self::topic('auto_transport.bikes_scooters', 'bikes-scooters'),
            ]),
            self::group('professional_business', '#7c2d12', $businessServices, [
                self::topic('legal_finance_business.lawyers', 'lawyers'),
                self::topic('legal_finance_business.accounting', 'accounting-tax', ['accounting']),
                self::topic('legal_finance_business.insurance', 'insurance'),
                self::topic('real_estate.real_estate_agents', 'real-estate-agents'),
                self::topic('legal_finance_business.financial_services', 'financial-mortgage', ['financial-services']),
                self::topic('legal_finance_business.business_consulting', 'business-consulting'),
                self::topic('professional_business.marketing_seo', 'marketing-seo', ['legal_finance_business.marketing_office_services', 'marketing-office-services']),
                self::topic('creators.graphic_designer', 'graphic-designer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES]),
                self::topic('professional_business.web_it', 'web-it'),
                self::topic('professionals.computer_technician', 'computer-technician', [], $userBusinessServices),
                self::topic('professionals.appliance_technician', 'appliance-technician', [], $userBusinessServices),
                self::topic('professionals.translation', 'translation', [], $userBusinessServices),
                self::topic('professional_business.office_services', 'office-services'),
                self::topic('professionals.professional_guidance', 'professional-guidance', [], $userBusinessServices),
                self::topic('professionals.coaching', 'coaching', [], $userBusinessServices),
            ]),
            self::group('digital_creators', '#9333ea', [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS], [
                self::topic('creators.photographer', 'photographer'),
                self::topic('creators.video_editor', 'video-editor', ['events_entertainment.video']),
                self::topic('creators.content_writer', 'content-writer'),
                self::topic('creators.illustrator', 'illustrator'),
                self::topic('creators.artist', 'artist'),
                self::topic('digital_creators.social_media', 'social-media'),
                self::topic('creators.handmade', 'handmade'),
                self::topic('creators.personalized_gifts', 'personalized-gifts'),
                self::topic('creators.fashion_designer', 'fashion-designer'),
                self::topic('creators.jewelry', 'jewelry'),
            ]),
            self::group('pets', '#65a30d', [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS], [
                self::topic('professionals.veterinarians', 'veterinarians'),
                self::topic('professionals.pet_grooming', 'pet-grooming'),
                self::topic('professionals.dog_training', 'dog-training'),
                self::topic('pets.pet_sitting_walking', 'pet-sitting-walking'),
                self::topic('pets.pet_supplies', 'pet-supplies'),
            ]),
            self::group('travel_leisure', '#0f766e', [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS], [
                self::topic('travel_transportation.trips_tours', 'trips-tours'),
                self::topic('travel_leisure.hotels_guesthouses', 'hotels-guesthouses'),
                self::topic('travel_transportation.travel_services', 'travel-agencies', ['travel-services']),
                self::topic('travel_leisure.leisure_activities', 'leisure-activities'),
                self::topic('travel_transportation.bus_services', 'bus-services'),
                self::topic('travel_transportation.private_transportation', 'private-transportation'),
                self::topic('travel_transportation.group_transportation', 'group-transportation'),
            ]),
            self::group('community_local', '#f97316', $community, [
                self::topic('community_pages.local.neighborhood_group', 'neighborhood-group'),
                self::topic('community_pages.local.residents', 'local-residents'),
                self::topic('community_pages.local.building_street', 'building-street-group'),
                self::topic('community_pages.local.help', 'local-help'),
            ]),
            self::group('community_religious', '#65a30d', $communityEvents, [
                self::topic('community_religious.synagogues_institutions', 'synagogues-institutions'),
                self::topic('community_religious.shiurim_torah_classes', 'torah-classes'),
                self::topic('community_pages.religious.holiday', 'holiday-community'),
                self::topic('community_religious.religious_services', 'religious-services'),
            ]),
            self::group('community_volunteering_charity', '#16a34a', $communityEvents, [
                self::topic('community_religious.charities', 'charities'),
                self::topic('community_pages.volunteering.volunteering', 'volunteering-groups'),
                self::topic('community_pages.volunteering.donations', 'donations'),
                self::topic('community_pages.volunteering.mutual_aid', 'mutual-aid'),
            ]),
            self::group('community_families_kids', '#e11d48', $communityEvents, [
                self::topic('community_pages.families.parent_group', 'parent-group'),
                self::topic('community_pages.families.school_group', 'school-group'),
                self::topic('community_pages.families.youth_movement', 'youth-movement'),
                self::topic('community_pages.families.kids_activities', 'community-kids-activities'),
            ]),
            self::group('community_culture_language', '#9333ea', $communityEvents, [
                self::topic('community_pages.culture.cultural_group', 'cultural-group'),
                self::topic('community_pages.culture.immigrant_group', 'immigrant-group'),
                self::topic('community_pages.culture.language_group', 'language-group'),
                self::topic('community_pages.culture.art_club', 'art-culture-club'),
            ]),
            self::group('community_sports_wellness', '#0891b2', $communityEvents, [
                self::topic('community_pages.sports.sports_club', 'sports-club'),
                self::topic('community_pages.sports.running_walking', 'running-walking-group'),
                self::topic('community_pages.sports.fitness_yoga', 'fitness-yoga-group'),
            ]),
            self::group('community_seniors_support', '#7c2d12', $communityEvents, [
                self::topic('community_pages.support.seniors_group', 'seniors-group'),
                self::topic('community_pages.support.health_support', 'health-support-group'),
                self::topic('community_pages.support.care_support', 'care-support-group'),
            ]),
            self::group('community_market_exchange', '#0284c7', $communityEvents, [
                self::topic('community_pages.market.swap_free', 'swap-free-items'),
                self::topic('community_pages.market.local_market', 'local-market'),
                self::topic('community_pages.market.community_sales', 'community-sales'),
            ]),
            self::group('community_public_municipal', '#475569', $communityEvents, [
                self::topic('community_pages.public.announcements', 'local-announcements'),
                self::topic('community_pages.public.safety_emergency', 'safety-emergency'),
                self::topic('community_pages.public.public_services', 'public-services'),
            ]),
            self::group('products_home_garden', '#0891b2', $products, [
                self::topic('products.home_garden.furniture', 'furniture-products', ['products.home_garden', 'home-garden-products']),
                self::topic('products.home_garden.home_decor', 'home-decor-products'),
                self::topic('products.home_garden.kitchen_dining', 'kitchen-dining-products'),
                self::topic('products.home_garden.bedding_textiles', 'bedding-textiles'),
                self::topic('products.home_garden.lighting', 'lighting-products'),
                self::topic('products.home_garden.tools_diy', 'tools-diy'),
                self::topic('products.home_garden.garden_outdoor', 'garden-outdoor-products'),
                self::topic('products.home_garden.cleaning_supplies', 'cleaning-supplies'),
                self::topic('products.home_garden.storage_organization', 'storage-organization'),
                self::topic('products.home_garden.grills', 'grills'),
            ]),
            self::group('products_electronics_computers', '#475569', $products, [
                self::topic('products.electronics_computers.phones_tablets', 'phones-tablets', ['products.electronics', 'electronics-products']),
                self::topic('products.electronics_computers.computers_laptops', 'computers-laptops'),
                self::topic('products.electronics_computers.tv_audio', 'tv-audio'),
                self::topic('products.electronics_computers.cameras', 'cameras'),
                self::topic('products.electronics_computers.gaming', 'gaming'),
                self::topic('products.electronics_computers.smart_home_security', 'smart-home-security-products'),
                self::topic('products.electronics_computers.accessories_cables', 'accessories-cables'),
                self::topic('products.electronics_computers.printers_office_tech', 'printers-office-tech'),
            ]),
            self::group('products_software', '#4f46e5', $products, [
                self::topic('products.software.operating_systems_utilities', 'operating-systems-utilities', ['products.software', 'software-products']),
                self::topic('products.software.games', 'gaming-software'),
                self::topic('products.software.mobile_apps', 'mobile-apps'),
                self::topic('products.software.business_productivity', 'business-productivity-software'),
                self::topic('products.software.finance_accounting', 'finance-accounting-software'),
                self::topic('products.software.communication_collaboration', 'communication-collaboration-software'),
                self::topic('products.software.cloud_saas', 'cloud-saas'),
                self::topic('products.software.ai_automation', 'ai-automation-software'),
                self::topic('products.software.design_creative', 'design-creative-software'),
                self::topic('products.software.media_entertainment', 'media-entertainment-software'),
                self::topic('products.software.security_antivirus', 'security-antivirus'),
                self::topic('products.software.developer_tools', 'developer-tools'),
                self::topic('products.software.website_plugins_themes', 'website-plugins-themes'),
                self::topic('products.software.education_learning', 'education-learning-software'),
            ]),
            self::group('products_appliances', '#64748b', $products, [
                self::topic('products.appliances.refrigerators_freezers', 'refrigerators-freezers'),
                self::topic('products.appliances.ovens_stoves', 'ovens-stoves'),
                self::topic('products.appliances.dishwashers', 'dishwashers'),
                self::topic('products.appliances.laundry', 'laundry-appliances'),
                self::topic('products.appliances.heating_cooling', 'heating-cooling-appliances'),
                self::topic('products.appliances.coffee_small_appliances', 'coffee-small-appliances'),
                self::topic('products.appliances.vacuum_cleaning_devices', 'vacuum-cleaning-devices'),
            ]),
            self::group('products_fashion_beauty', '#e11d48', $products, [
                self::topic('products.fashion_beauty.women_clothing', 'women-clothing', ['products.fashion_beauty', 'fashion-beauty-products']),
                self::topic('products.fashion_beauty.men_clothing', 'men-clothing'),
                self::topic('products.fashion_beauty.kids_clothing', 'kids-clothing'),
                self::topic('products.fashion_beauty.shoes', 'shoes'),
                self::topic('products.fashion_beauty.bags_accessories', 'bags-accessories'),
                self::topic('products.fashion_beauty.jewelry_watches', 'jewelry-watches-products'),
                self::topic('products.fashion_beauty.wigs_head_coverings', 'wigs-head-coverings'),
                self::topic('products.fashion_beauty.cosmetics_skin_care', 'cosmetics-skin-care-products'),
                self::topic('products.fashion_beauty.perfume', 'perfume'),
                self::topic('products.fashion_beauty.hair_nail_products', 'hair-nail-products'),
            ]),
            self::group('products_kids_baby', '#f59e0b', $products, [
                self::topic('products.kids_baby.strollers_car_seats', 'strollers-car-seats', ['products.kids_baby', 'kids-baby-products']),
                self::topic('products.kids_baby.baby_furniture', 'baby-furniture'),
                self::topic('products.kids_baby.toys_games', 'toys-games'),
                self::topic('products.kids_baby.school_supplies', 'school-supplies-products'),
                self::topic('products.kids_baby.baby_clothing', 'baby-clothing'),
                self::topic('products.kids_baby.feeding_care', 'feeding-care'),
            ]),
            self::group('products_food_grocery', '#ef4444', $products, [
                self::topic('products.food_grocery.groceries_pantry', 'groceries-pantry', ['products.food_grocery', 'food-grocery-products']),
                self::topic('products.food_grocery.bakery', 'bakery-products'),
                self::topic('products.food_grocery.prepared_food', 'prepared-food-products'),
                self::topic('products.food_grocery.meat_fish_deli', 'meat-fish-deli'),
                self::topic('products.food_grocery.fruit_veg', 'fruit-vegetables'),
                self::topic('products.food_grocery.kosher_specialty', 'kosher-specialty-products'),
                self::topic('products.food_grocery.drinks_wine', 'drinks-wine'),
            ]),
            self::group('products_sports_leisure', '#0284c7', $products, [
                self::topic('products.sports_leisure.fitness_equipment', 'fitness-equipment', ['products.sports_leisure', 'sports-leisure-products']),
                self::topic('products.sports_leisure.bikes_scooters', 'bikes-scooters-products'),
                self::topic('products.sports_leisure.outdoor_camping', 'outdoor-camping'),
                self::topic('products.sports_leisure.sportswear', 'sportswear'),
                self::topic('products.sports_leisure.hobbies_crafts', 'hobbies-crafts'),
                self::topic('products.sports_leisure.books_media', 'books-media'),
                self::topic('products.sports_leisure.musical_instruments', 'musical-instruments'),
            ]),
            self::group('products_gifts_handmade', '#9333ea', $products, [
                self::topic('products.gifts_handmade.flowers', 'flowers', ['products.gifts_handmade', 'gifts-handmade-products']),
                self::topic('products.gifts_handmade.handmade_items', 'handmade-items'),
                self::topic('products.gifts_handmade.personalized_gifts', 'personalized-gifts-products'),
                self::topic('products.gifts_handmade.art', 'art-products'),
                self::topic('products.gifts_handmade.judaica', 'judaica'),
                self::topic('products.gifts_handmade.party_supplies', 'party-supplies'),
            ]),
            self::group('products_pets', '#65a30d', $products, [
                self::topic('products.pets.food', 'pet-food', ['products.pets', 'pet-products']),
                self::topic('products.pets.accessories', 'pet-accessories'),
                self::topic('products.pets.aquariums', 'aquariums'),
                self::topic('products.pets.bird_small_pet_supplies', 'bird-small-pet-supplies'),
            ]),
            self::group('products_cars_accessories', '#7c2d12', $products, [
                self::topic('products.cars_accessories.car_accessories', 'car-accessory-products', ['products.cars_accessories']),
                self::topic('products.cars_accessories.car_electronics', 'car-electronics'),
                self::topic('products.cars_accessories.tires_wheels', 'tires-wheels'),
                self::topic('products.cars_accessories.tools', 'car-tools'),
                self::topic('products.cars_accessories.bike_scooter_parts', 'bike-scooter-parts'),
            ]),
            self::group('products_office_business', '#0f766e', $products, [
                self::topic('products.office_business.office_furniture', 'office-furniture', ['products.office_business', 'office-business-products']),
                self::topic('products.office_business.stationery', 'stationery'),
                self::topic('products.office_business.toner_printers', 'toner-printers'),
                self::topic('products.office_business.packaging', 'packaging'),
                self::topic('products.office_business.professional_equipment', 'professional-equipment'),
            ]),
            self::group('services_cleaning_moving', '#f97316', $services, [
                self::topic('services.cleaning_moving.house_cleaning', 'house-cleaning', ['services.cleaning_moving', 'cleaning-moving-services']),
                self::topic('services.cleaning_moving.office_cleaning', 'office-cleaning'),
                self::topic('services.cleaning_moving.floor_polish', 'floor-polish'),
                self::topic('services.cleaning_moving.moving', 'moving-services'),
                self::topic('services.cleaning_moving.packing', 'packing-services'),
                self::topic('services.cleaning_moving.storage', 'storage-services'),
                self::topic('services.cleaning_moving.waste_removal_recycling', 'waste-removal-recycling'),
            ]),
            self::group('services_beauty_wellness', '#e11d48', $services, [
                self::topic('services.beauty_wellness.hair', 'hair-services', ['services.beauty_wellness', 'beauty-wellness-services']),
                self::topic('services.beauty_wellness.makeup', 'makeup-services'),
                self::topic('services.beauty_wellness.nails', 'nail-services'),
                self::topic('services.beauty_wellness.skin_care', 'skin-care-services'),
                self::topic('services.beauty_wellness.massage_spa', 'massage-spa-services'),
                self::topic('services.beauty_wellness.personal_training', 'personal-training-services'),
                self::topic('services.beauty_wellness.nutrition', 'nutrition-services'),
                self::topic('services.beauty_wellness.alternative_medicine', 'alternative-medicine-services'),
            ]),
            self::group('services_health_care', '#16a34a', $services, [
                self::topic('services.health_care.physiotherapy', 'physiotherapy-services', ['services.health_care', 'health-care-services']),
                self::topic('services.health_care.therapy_counseling', 'therapy-counseling-services'),
                self::topic('services.health_care.nursing_caregiver', 'nursing-caregiver'),
                self::topic('services.health_care.senior_care', 'senior-care-services'),
                self::topic('services.health_care.medical_massage', 'medical-massage-services'),
                self::topic('services.health_care.medical_equipment_service', 'medical-equipment-service'),
            ]),
            self::group('services_education_lessons', '#7c3aed', $services, [
                self::topic('services.education_lessons.private_tutors', 'private-tutor-services', ['services.education_lessons', 'education-lesson-services']),
                self::topic('services.education_lessons.language_lessons', 'language-lesson-services'),
                self::topic('services.education_lessons.music_lessons', 'music-lesson-services'),
                self::topic('services.education_lessons.courses_workshops', 'course-workshop-services'),
                self::topic('services.education_lessons.driving_lessons', 'driving-lesson-services'),
                self::topic('services.education_lessons.kids_activities', 'kids-activity-services'),
                self::topic('services.education_lessons.religious_studies', 'religious-study-services'),
            ]),
            self::group('services_events_entertainment', '#f54291', $services, [
                self::topic('services.events_entertainment.event_production', 'event-production-services', ['services.events_entertainment', 'event-entertainment-services']),
                self::topic('services.events_entertainment.dj_music', 'dj-music-services'),
                self::topic('services.events_entertainment.photography_video', 'photography-video-services'),
                self::topic('services.events_entertainment.catering', 'catering-services'),
                self::topic('services.events_entertainment.venues', 'venue-services'),
                self::topic('services.events_entertainment.party_equipment', 'party-equipment-services', [], [self::SCOPE_SERVICES, self::SCOPE_PRODUCTS]),
                self::topic('services.events_entertainment.attractions', 'event-attraction-services'),
                self::topic('services.events_entertainment.kids_entertainer', 'kids-entertainer-services'),
                self::topic('services.events_entertainment.flowers_decor', 'event-decor-services'),
            ]),
            self::group('services_business_professional', '#7c2d12', $services, [
                self::topic('services.business_professional.accounting_tax', 'accounting-tax-services', ['services.business_finance', 'business-finance-services']),
                self::topic('services.business_professional.law', 'law-services', ['services.legal_professional', 'legal-professional-services']),
                self::topic('services.business_professional.insurance', 'insurance-services'),
                self::topic('services.business_professional.real_estate_brokerage', 'real-estate-brokerage'),
                self::topic('services.business_professional.marketing_seo', 'marketing-seo-services'),
                self::topic('services.business_professional.graphic_design_branding', 'graphic-design-branding'),
                self::topic('services.business_professional.web_it_support', 'web-it-support'),
                self::topic('services.business_professional.translation', 'translation-services'),
                self::topic('services.business_professional.consulting_coaching', 'consulting-coaching'),
                self::topic('services.business_professional.office_admin', 'office-admin-services'),
            ]),
            self::group('services_transportation', '#0284c7', $services, [
                self::topic('services.transportation.shuttles_taxis', 'shuttles-taxis-services', ['services.transportation', 'transportation-services']),
                self::topic('services.transportation.courier', 'courier-services'),
                self::topic('services.transportation.car_rental_leasing', 'car-rental-leasing-services'),
                self::topic('services.transportation.garage_repair', 'garage-repair-services'),
                self::topic('services.transportation.towing_roadside', 'towing-roadside-services'),
                self::topic('services.transportation.tours_trips', 'tours-trips-services'),
            ]),
            self::group('services_pets', '#65a30d', $services, [
                self::topic('services.pets.grooming', 'pet-grooming-services', ['services.pets', 'pet-services']),
                self::topic('services.pets.veterinary', 'veterinary-services'),
                self::topic('services.pets.dog_training', 'dog-training-services'),
                self::topic('services.pets.pet_sitting_walking', 'pet-sitting-walking-services'),
            ]),
            self::group('services_creative_digital', '#9333ea', $services, [
                self::topic('services.creative_digital.content_writing', 'content-writing-services'),
                self::topic('services.creative_digital.video_editing', 'video-editing-services'),
                self::topic('services.creative_digital.photography', 'photography-services'),
                self::topic('services.creative_digital.illustration_art', 'illustration-art-services'),
                self::topic('services.creative_digital.social_media', 'social-media-services'),
                self::topic('services.creative_digital.handmade_custom_design', 'handmade-custom-design'),
            ]),
            self::group('events_community_social', '#f97316', $events, [
                self::topic('events.community_social.neighborhood_meeting', 'neighborhood-meeting', ['events.community', 'community-events-local']),
                self::topic('events.community_social.community_festival', 'community-festival'),
                self::topic('events.community_social.charity_fundraiser', 'charity-fundraiser'),
                self::topic('events.community_social.volunteering', 'volunteering-events'),
                self::topic('events.community_social.local_market_swap', 'local-market-swap'),
                self::topic('events.community_social.public_meeting', 'public-meeting'),
            ]),
            self::group('events_kids_family', '#e11d48', $events, [
                self::topic('events.kids_family.kids_show', 'kids-show', ['events.kids_family', 'kids-family-events']),
                self::topic('events.kids_family.family_activity', 'family-activity'),
                self::topic('events.kids_family.story_time', 'story-time'),
                self::topic('events.kids_family.camps', 'kids-camps'),
                self::topic('events.kids_family.parenting_event', 'parenting-event'),
            ]),
            self::group('events_classes_workshops', '#7c3aed', $events, [
                self::topic('events.classes_workshops.lecture', 'lectures', ['events.classes_workshops', 'classes-workshops']),
                self::topic('events.classes_workshops.course', 'courses'),
                self::topic('events.classes_workshops.art_craft_workshop', 'art-craft-workshop'),
                self::topic('events.classes_workshops.cooking_workshop', 'cooking-workshop'),
                self::topic('events.classes_workshops.language_class', 'language-class'),
                self::topic('events.classes_workshops.professional_workshop', 'professional-workshop'),
                self::topic('events.classes_workshops.torah_religious_class', 'torah-religious-class'),
            ]),
            self::group('events_music_shows', '#f54291', $events, [
                self::topic('events.music_shows.concert', 'concerts', ['events.music_shows', 'music-shows']),
                self::topic('events.music_shows.dj_party', 'dj-party'),
                self::topic('events.music_shows.theater', 'theater'),
                self::topic('events.music_shows.standup', 'standup'),
                self::topic('events.music_shows.dance', 'dance-show'),
                self::topic('events.music_shows.open_mic', 'open-mic'),
            ]),
            self::group('events_markets_sales', '#0891b2', $events, [
                self::topic('events.markets_sales.garage_sale', 'garage-sale', ['events.markets_sales', 'markets-sales']),
                self::topic('events.markets_sales.pop_up_shop', 'pop-up-shop'),
                self::topic('events.markets_sales.food_fair', 'food-fair'),
                self::topic('events.markets_sales.craft_fair', 'craft-fair'),
                self::topic('events.markets_sales.holiday_market', 'holiday-market'),
            ]),
            self::group('events_religious_jewish', '#65a30d', $events, [
                self::topic('events.religious_jewish.shiur', 'shiur', ['events.religious', 'religious-events']),
                self::topic('events.religious_jewish.synagogue_event', 'synagogue-event'),
                self::topic('events.religious_jewish.holiday_event', 'holiday-event'),
                self::topic('events.religious_jewish.shabbat_meal', 'shabbat-meal'),
                self::topic('events.religious_jewish.community_celebration', 'community-celebration'),
            ]),
            self::group('events_sports_fitness', '#0284c7', $events, [
                self::topic('events.sports_fitness.group_workout', 'group-workout', ['events.sports', 'sports-events']),
                self::topic('events.sports_fitness.run_walk', 'run-walk'),
                self::topic('events.sports_fitness.tournament', 'tournament'),
                self::topic('events.sports_fitness.yoga_pilates', 'yoga-pilates'),
                self::topic('events.sports_fitness.outdoor_activity', 'outdoor-activity'),
            ]),
            self::group('events_business_networking', '#7c2d12', $events, [
                self::topic('events.business_networking.networking', 'networking', ['events.business_networking', 'business-networking-events']),
                self::topic('events.business_networking.meetup', 'meetup'),
                self::topic('events.business_networking.seminar', 'seminar'),
                self::topic('events.business_networking.business_workshop', 'business-workshop'),
                self::topic('events.business_networking.expo', 'expo'),
            ]),
            self::group('events_food_culture', '#ef4444', $events, [
                self::topic('events.food_culture.tasting', 'tasting', ['events.food_culture', 'food-culture-events']),
                self::topic('events.food_culture.restaurant_event', 'restaurant-event'),
                self::topic('events.food_culture.cultural_evening', 'cultural-evening'),
                self::topic('events.food_culture.exhibition', 'exhibition'),
                self::topic('events.food_culture.film', 'film-event'),
            ]),
            self::group('events_online_hybrid', '#475569', $events, [
                self::topic('events.online_hybrid.webinar', 'webinar'),
                self::topic('events.online_hybrid.online_class', 'online-class'),
                self::topic('events.online_hybrid.live_stream', 'live-stream'),
            ]),
        ];

        return self::$groups;
    }

    private static function group(string $key, string $color, array $scopes, array $topics): array
    {
        return [
            'key' => $key,
            'labels' => self::catalogLabels($key),
            'color' => $color,
            'scopes' => $scopes,
            'topics' => $topics,
        ];
    }

    private static function topic(string $key, string $slug, array $aliases = [], ?array $scopes = null): array
    {
        return [
            'key' => $key,
            'slug' => $slug,
            'labels' => self::catalogLabels($key),
            'aliases' => $aliases,
            'scopes' => $scopes,
        ];
    }

    /** @return array{he: string, en: string, ru: string, fr: string} */
    private static function catalogLabels(string $key): array
    {
        return CatalogTopicTranslations::labels($key)
            ?? throw new \LogicException("Missing catalog translations for [{$key}].");
    }

    private static function labels(string $he, string $en, ?string $ru = null, ?string $fr = null): array
    {
        return [
            'he' => $he,
            'en' => $en,
            'ru' => $ru ?? $en,
            'fr' => $fr ?? $en,
        ];
    }

    private static function scopeHub(
        string $slug,
        array $scopes,
        string $he,
        string $en,
        ?string $ru,
        ?string $fr,
        string $descriptionHe,
        string $descriptionEn,
        ?string $descriptionRu = null,
        ?string $descriptionFr = null
    ): array {
        return [
            'slug' => $slug,
            'path' => '/catalog/'.$slug,
            'scopes' => $scopes,
            'labels' => self::labels($he, $en, $ru, $fr),
            'descriptions' => self::labels($descriptionHe, $descriptionEn, $descriptionRu, $descriptionFr),
        ];
    }

    private static function groupPayload(array $group): array
    {
        return [
            'key' => $group['key'],
            'labels' => $group['labels'],
            'color' => $group['color'],
            'topics' => collect($group['topics'])
                ->map(fn (array $topic) => self::topicPayload($topic, $group))
                ->values()
                ->all(),
        ];
    }

    private static function topicPayload(array $topic, array $group): array
    {
        $scopes = collect($topic['scopes'] ?? $group['scopes']);

        // Products can always be promoted as ads; ad-only topics such as jobs stay out of stores.
        if ($scopes->contains(self::SCOPE_PRODUCTS)) {
            $scopes->push(self::SCOPE_ADS);
        }

        return [
            'key' => $topic['key'],
            'slug' => $topic['slug'],
            'labels' => $topic['labels'],
            'color' => $topic['color'] ?? $group['color'],
            'group_key' => $group['key'],
            'group_labels' => $group['labels'],
            'scopes' => $scopes->unique()->values()->all(),
            'aliases' => collect(Arr::wrap($topic['aliases'] ?? []))->unique()->values()->all(),
        ];
    }

    private static function marketProductTypePayload(array $type): array
    {
        return [
            'key' => $type['key'],
            'slug' => $type['slug'],
            'market_slug' => $type['slug'],
            'labels' => $type['labels'],
            'color' => $type['color'],
            'scopes' => [self::SCOPE_PRODUCTS],
            'topic_keys' => collect($type['topic_keys'])
                ->map(fn (string $key): ?string => self::canonicalKeyForScope($key, self::SCOPE_PRODUCTS))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }
}
