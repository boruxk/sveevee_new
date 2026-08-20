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
        'beauty_personal_care.skin_care' => 'professionals.cosmetics',
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
        'beauty_personal_care.cosmetics' => 'professionals.cosmetics',
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

    public static function publicPayload(?array $scopes = null): array
    {
        $normalizedScopes = self::normalizeScopes($scopes);
        $groups = $normalizedScopes === []
            ? self::groups()
            : self::scopedOptionsForScopes($normalizedScopes);

        return [
            'groups' => $groups,
            'popular_topics' => collect(self::POPULAR_KEYS)
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
                'Parcourez les entreprises locales par categorie, ville et quartier.'
            ),
            self::scopeHub(
                'communities',
                [self::SCOPE_COMMUNITY_PAGES],
                'קהילות',
                'Communities',
                'Сообщества',
                'Communautes',
                'עמודי קהילה מקומיים, קבוצות, עזרה ואירועים שכונתיים.',
                'Find local community pages, groups, help, and neighborhood activity.',
                'Найдите местные сообщества, группы, помощь и районные события.',
                'Trouvez des pages communautaires locales, groupes, aide et activites de quartier.'
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
                'Parcourez les produits des commerces locaux par categorie et zone.'
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
                'Trouvez des services et professionnels locaux par categorie et zone.'
            ),
            self::scopeHub(
                'events',
                [self::SCOPE_EVENTS],
                'אירועים',
                'Events',
                'События',
                'Evenements',
                'אירועים מקומיים, סדנאות, מופעים ופעילות קהילתית.',
                'Discover local events, workshops, shows, and community activity.',
                'Местные события, мастер-классы, выступления и активности.',
                'Decouvrez les evenements locaux, ateliers, spectacles et activites.'
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
                'Parcourez les annonces locales gratuites par categorie et zone.'
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
                'Trouvez des professionnels, artistes et createurs locaux par categorie et zone.'
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
            self::group('ads_real_estate', 'נדל"ן', 'Real estate ads', '#2563eb', $ads, [
                self::topic('real_estate.for_sale', 'real-estate-for-sale', 'נדל"ן למכירה', 'Real estate for sale'),
                self::topic('real_estate.for_rent', 'real-estate-for-rent', 'נדל"ן להשכרה', 'Real estate for rent'),
                self::topic('real_estate.commercial', 'commercial-real-estate', 'נדל"ן מסחרי', 'Commercial real estate'),
                self::topic('real_estate.storage', 'storage-real-estate', 'מחסנים ואחסון', 'Storage'),
                self::topic('real_estate.vacation_short_term_rental', 'vacation-short-term-rentals', 'נופש והשכרה לטווח קצר', 'Vacation & short-term rentals'),
            ]),
            self::group('ads_jobs', 'עבודה', 'Jobs', '#16a34a', $ads, [
                self::topic('jobs.job_offers', 'job-offers', 'הצעות עבודה', 'Job offers'),
                self::topic('jobs.job_seekers', 'job-seekers', 'מחפשי עבודה', 'Job seekers'),
                self::topic('jobs.office_administration', 'office-administration-jobs', 'משרד ואדמיניסטרציה', 'Office & administration jobs'),
                self::topic('jobs.sales', 'sales-jobs', 'מכירות', 'Sales jobs'),
                self::topic('jobs.education', 'education-jobs', 'חינוך', 'Education jobs'),
                self::topic('jobs.childcare', 'childcare-jobs', 'טיפול בילדים', 'Childcare jobs'),
                self::topic('jobs.cleaning', 'cleaning-jobs', 'ניקיון', 'Cleaning jobs'),
                self::topic('jobs.caregiving', 'caregiving-jobs', 'סיעוד וטיפול', 'Caregiving jobs'),
                self::topic('jobs.part_time', 'part-time-jobs', 'משרה חלקית', 'Part-time jobs'),
            ]),
            self::group('home_renovation_building', 'בית, שיפוצים ובנייה', 'Home, renovation & building', '#f97316', $businessServices, [
                self::topic('professionals.electricians', 'electricians', 'חשמלאים', 'Electricians', [], $userBusinessServices),
                self::topic('services.home_repairs.plumbing', 'plumbing', 'אינסטלטורים', 'Plumbers'),
                self::topic('services.home_repairs.handyman', 'handyman-services', 'הנדימן', 'Handyman', ['services.home_repairs', 'home-repair-services']),
                self::topic('professionals.renovation', 'renovation', 'שיפוצים', 'Renovation', [], $userBusinessServices),
                self::topic('professionals.building_contractors', 'building-contractors', 'קבלני בניין', 'Building contractors', [], $userBusinessServices),
                self::topic('professionals.interior_design', 'interior-design', 'עיצוב פנים', 'Interior design', [], $userBusinessServices),
                self::topic('services.home_repairs.air_conditioning', 'air-conditioning', 'מיזוג אוויר', 'Air conditioning'),
                self::topic('services.home_repairs.painting', 'painting', 'צביעה', 'Painting'),
                self::topic('professionals.drywall', 'drywall', 'עבודות גבס', 'Drywall', ['professionals.gypsum'], $userBusinessServices),
                self::topic('professionals.sealing_roofing', 'sealing-roofing', 'איטום וזיפות', 'Roofing & sealing', [], $userBusinessServices),
                self::topic('services.home_repairs.carpentry', 'carpentry', 'נגרות', 'Carpentry'),
                self::topic('services.home_repairs.windows_shutters', 'windows-shutters', 'חלונות ותריסים', 'Windows & shutters'),
                self::topic('services.home_repairs.locksmith', 'locksmith', 'מנעולנים', 'Locksmiths'),
                self::topic('professionals.pest_control', 'pest-control', 'הדברה וריסוס', 'Pest control', [], $userBusinessServices),
                self::topic('services.home_repairs.gardening', 'gardening-landscaping', 'גינון ועיצוב גינות', 'Gardening & landscaping'),
                self::topic('professionals.cleaning_polish', 'cleaning-polish', 'ניקיון ופוליש', 'Cleaning & polish', [], $userBusinessServices),
                self::topic('professionals.moving', 'moving', 'הובלות', 'Moving', [], $userBusinessServices),
                self::topic('professionals.security', 'security-smart-home', 'מיגון ובית חכם', 'Security & smart home', ['security'], $userBusinessServices),
                self::topic('professionals.sewage_contractors', 'sewage-contractors', 'קבלני ביוב', 'Sewage contractors', [], $userBusinessServices),
                self::topic('professionals.waste_recycling', 'waste-recycling', 'פינוי ומחזור פסולת', 'Waste removal & recycling', [], $userBusinessServices),
                self::topic('professionals.industrial_design', 'industrial-design', 'עיצוב תעשייתי', 'Industrial design', [], $userBusinessServices),
                self::topic('professionals.prefab_building', 'prefab-building', 'בנייה מתועשת', 'Prefab building', [], $userBusinessServices),
            ]),
            self::group('food_hospitality', 'אוכל ואירוח', 'Food & hospitality', '#ef4444', $businessProducts, [
                self::topic('food_catering.restaurants', 'restaurants', 'מסעדות', 'Restaurants'),
                self::topic('food_catering.cafes', 'cafes', 'בתי קפה', 'Cafes'),
                self::topic('food_catering.bakery', 'bakery', 'מאפיות', 'Bakeries'),
                self::topic('professionals.catering', 'catering', 'קייטרינג', 'Catering', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.fast_food', 'fast-food', 'מזון מהיר', 'Fast food', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('food_catering.meat_deli', 'meat-deli', 'בשר ומעדניות', 'Meat & deli'),
                self::topic('professionals.fish_restaurants', 'fish-restaurants', 'דגים', 'Fish & seafood', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.grocery_food', 'grocery-food', 'מכולת ומזון', 'Grocery & food stores', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('food_catering.prepared_food', 'prepared-food', 'אוכל מוכן', 'Prepared food'),
                self::topic('food_catering.food_trucks', 'food-trucks', 'פוד טראקס', 'Food trucks'),
                self::topic('food_catering.kosher_specialty', 'kosher-specialty-food', 'כשר ומזון ייחודי', 'Kosher & specialty food'),
                self::topic('food_catering.bars', 'bars', 'ברים', 'Bars'),
            ]),
            self::group('shopping_retail', 'קניות וחנויות', 'Shopping & retail', '#0891b2', $businessProducts, [
                self::topic('shopping_retail.fashion', 'fashion-stores', 'אופנה', 'Fashion'),
                self::topic('shopping_retail.shoes_bags', 'shoes-bags', 'נעליים ותיקים', 'Shoes & bags'),
                self::topic('shopping_retail.jewelry_watches', 'jewelry-watches', 'תכשיטים ושעונים', 'Jewelry & watches'),
                self::topic('shopping_retail.gifts_flowers', 'gifts-flowers', 'מתנות ופרחים', 'Gifts & flowers', ['shopping_retail.gifts', 'gifts']),
                self::topic('shopping_retail.furniture_home_decor', 'furniture-home-decor', 'רהיטים ועיצוב הבית', 'Furniture & home decor', ['shopping_retail.furniture', 'furniture']),
                self::topic('shopping_retail.kitchen_home_goods', 'kitchen-home-goods', 'מטבח ומוצרי בית', 'Kitchen & home goods', ['shopping_retail.kitchen', 'kitchen', 'shopping_retail.household_goods', 'household-goods']),
                self::topic('shopping_retail.electronics', 'electronics-stores', 'אלקטרוניקה', 'Electronics'),
                self::topic('shopping_retail.appliances', 'appliance-stores', 'מוצרי חשמל', 'Appliances'),
                self::topic('shopping_retail.books_stationery', 'books-stationery', 'ספרים וציוד משרדי', 'Books & stationery', ['shopping_retail.school_supplies', 'school-supplies']),
                self::topic('shopping_retail.baby_kids', 'baby-kids-stores', 'תינוקות וילדים', 'Baby & kids'),
                self::topic('shopping_retail.sports_outdoor', 'sports-outdoor-stores', 'ספורט ופנאי', 'Sports & outdoor'),
                self::topic('shopping_retail.pet_stores', 'pet-stores', 'חנויות לחיות', 'Pet stores'),
                self::topic('shopping_retail.sales_special_offers', 'special-offers', 'מבצעים והטבות', 'Sales & special offers'),
            ]),
            self::group('beauty_wellness', 'יופי ואורח חיים', 'Beauty & wellness', '#e11d48', $businessServices, [
                self::topic('professionals.hair_salons', 'hair-salons', 'עיצוב שיער ומספרות', 'Hair salons', [], $userBusinessServices),
                self::topic('professionals.beauticians', 'beauticians', 'קוסמטיקאיות', 'Beauticians', [], $userBusinessServices),
                self::topic('beauty_personal_care.nails', 'nails', 'ציפורניים', 'Nails'),
                self::topic('beauty_personal_care.makeup', 'makeup', 'איפור', 'Makeup'),
                self::topic('professionals.cosmetics', 'cosmetics', 'קוסמטיקה וטיפוח', 'Cosmetics & skin care', [], $userBusinessServices),
                self::topic('professionals.beauty_salons', 'beauty-salons', 'מכוני יופי', 'Beauty salons', [], $userBusinessServices),
                self::topic('beauty_personal_care.spa_massage', 'spa-massage', 'ספא ועיסוי', 'Massage & spa'),
                self::topic('professionals.personal_trainer', 'personal-trainer', 'מאמן כושר אישי', 'Personal trainer', [], $userBusinessServices),
                self::topic('professionals.nutrition', 'nutrition', 'דיאטה ותזונה', 'Nutrition', [], $userBusinessServices),
                self::topic('professionals.alternative_medicine', 'alternative-medicine', 'רפואה משלימה', 'Alternative medicine', [], $userBusinessServices),
            ]),
            self::group('health_care', 'בריאות וטיפול', 'Health & care', '#16a34a', $businessServices, [
                self::topic('health_care.dentists', 'dentists', 'רופאי שיניים', 'Dentists'),
                self::topic('health_care.clinics_doctors', 'clinics-doctors', 'מרפאות ורופאים', 'Clinics & doctors'),
                self::topic('health_care.physiotherapy', 'physiotherapy', 'פיזיותרפיה', 'Physiotherapy'),
                self::topic('health_care.therapy_counseling', 'therapy-counseling', 'טיפול וייעוץ רגשי', 'Therapy & counseling'),
                self::topic('health_care.senior_care', 'senior-care', 'טיפול בקשישים', 'Senior care'),
                self::topic('health_care.caregivers_nursing', 'caregivers-nursing', 'מטפלים וסיעוד', 'Caregivers & nursing'),
                self::topic('health_care.pharmacies', 'pharmacies', 'בתי מרקחת', 'Pharmacies'),
                self::topic('health_care.medical_equipment', 'medical-equipment', 'ציוד רפואי', 'Medical equipment'),
                self::topic('professionals.medical_massage', 'medical-massage', 'עיסוי רפואי', 'Medical massage', [], $userBusinessServices),
            ]),
            self::group('education_kids', 'חינוך וילדים', 'Education & kids', '#7c3aed', [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS], [
                self::topic('professionals.private_tutors', 'private-tutors', 'מורים פרטיים', 'Private tutors', ['education_courses.private_lessons'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES]),
                self::topic('education_courses.courses_workshops', 'courses-workshops', 'קורסים וסדנאות', 'Courses & workshops'),
                self::topic('professionals.language_lessons', 'language-lessons', 'לימודי שפה', 'Language lessons', [], $userBusinessServices),
                self::topic('professionals.music_lessons', 'music-lessons', 'לימודי נגינה', 'Music lessons', [], $userBusinessServices),
                self::topic('education_courses.driving_lessons', 'driving-lessons', 'שיעורי נהיגה', 'Driving lessons'),
                self::topic('education_courses.daycare_kindergarten', 'daycare-kindergarten', 'מעונות וגני ילדים', 'Daycare & kindergarten'),
                self::topic('professionals.kids_activities', 'kids-activities', 'הפעלות ילדים', 'Kids activities', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('education_courses.professional_training', 'professional-training', 'הכשרה מקצועית', 'Professional training'),
                self::topic('education_courses.religious_studies', 'religious-studies', 'לימודי קודש', 'Religious studies'),
            ]),
            self::group('events_entertainment', 'אירועים ובידור', 'Events & entertainment', '#f54291', [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS], [
                self::topic('professionals.event_production', 'event-production', 'ארגון והפקת אירועים', 'Event production', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.dj', 'dj', 'DJ', 'DJ', ['events_entertainment.music_dj'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.musician_singer', 'musician-singer', 'מוזיקאי / זמר', 'Musician / singer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('professionals.photo_video', 'photo-video', 'צילום ועריכה', 'Photography & video', ['events_entertainment.event_photography'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES]),
                self::topic('professionals.venues', 'venues', 'אולמות ומקומות', 'Venues', ['events_entertainment.event_venues'], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('events_entertainment.party_equipment', 'party-equipment', 'ציוד לאירועים', 'Party equipment'),
                self::topic('entertainers.kids_entertainer', 'kids-entertainer', 'מפעיל ילדים / ליצן', 'Kids entertainer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.event_attractions', 'event-attractions', 'אטרקציות לאירועים', 'Event attractions', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('events_entertainment.flowers_decor', 'event-flowers-decor', 'פרחים ועיצוב אירועים', 'Flowers & event decor'),
                self::topic('entertainers.magician', 'magician', 'קוסם', 'Magician', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.event_host', 'event-host', 'מנחה אירועים', 'Event host', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.dancer_band', 'dancer-band', 'רקדן / להקה', 'Dancer / band', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.comedian', 'comedian', 'סטנדאפיסט', 'Comedian', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.actor', 'actor', 'שחקן', 'Actor', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.street_performer', 'street-performer', 'אמן רחוב', 'Street performer', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
                self::topic('entertainers.karaoke', 'karaoke', 'קריוקי', 'Karaoke', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS]),
            ]),
            self::group('auto_transport', 'רכב ותחבורה', 'Auto & transport', '#0284c7', $businessServices, [
                self::topic('professionals.garages', 'garages', 'מוסכים', 'Garages', [], $userBusinessServices),
                self::topic('professionals.car_accessories', 'car-accessories', 'אביזרי רכב', 'Car accessories', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_PRODUCTS]),
                self::topic('professionals.car_rental', 'car-rental', 'השכרת רכב', 'Car rental', [], $userBusinessServices),
                self::topic('professionals.leasing', 'leasing', 'ליסינג', 'Leasing', [], $userBusinessServices),
                self::topic('professionals.shuttles', 'shuttles-taxis', 'הסעות ומוניות', 'Shuttles & taxis', ['shuttles'], $userBusinessServices),
                self::topic('professionals.courier', 'courier-delivery', 'שליחויות ומשלוחים', 'Courier & delivery', ['courier'], $userBusinessServices),
                self::topic('auto_transport.towing_roadside', 'towing-roadside', 'גרירה וסיוע בדרכים', 'Towing & roadside'),
                self::topic('auto_transport.bikes_scooters', 'bikes-scooters', 'אופניים וקורקינטים', 'Bikes & scooters'),
            ]),
            self::group('professional_business', 'מקצועי ועסקי', 'Professional & business', '#7c2d12', $businessServices, [
                self::topic('legal_finance_business.lawyers', 'lawyers', 'עורכי דין', 'Lawyers'),
                self::topic('legal_finance_business.accounting', 'accounting-tax', 'ראיית חשבון ומסים', 'Accounting & tax', ['accounting']),
                self::topic('legal_finance_business.insurance', 'insurance', 'ביטוח', 'Insurance'),
                self::topic('real_estate.real_estate_agents', 'real-estate-agents', 'מתווכים', 'Real estate agents'),
                self::topic('legal_finance_business.financial_services', 'financial-mortgage', 'פיננסים ומשכנתאות', 'Finance & mortgage', ['financial-services']),
                self::topic('legal_finance_business.business_consulting', 'business-consulting', 'ייעוץ עסקי', 'Business consulting'),
                self::topic('professional_business.marketing_seo', 'marketing-seo', 'שיווק ו-SEO', 'Marketing & SEO', ['legal_finance_business.marketing_office_services', 'marketing-office-services']),
                self::topic('creators.graphic_designer', 'graphic-designer', 'עיצוב גרפי', 'Graphic design', [], [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES]),
                self::topic('professional_business.web_it', 'web-it', 'בניית אתרים ו-IT', 'Web & IT'),
                self::topic('professionals.computer_technician', 'computer-technician', 'טכנאי מחשבים', 'Computer technician', [], $userBusinessServices),
                self::topic('professionals.appliance_technician', 'appliance-technician', 'טכנאי מוצרי חשמל', 'Appliance technician', [], $userBusinessServices),
                self::topic('professionals.translation', 'translation', 'תרגום', 'Translation', [], $userBusinessServices),
                self::topic('professional_business.office_services', 'office-services', 'שירותי משרד', 'Office services'),
                self::topic('professionals.professional_guidance', 'professional-guidance', 'ייעוץ והכוונה מקצועית', 'Professional guidance', [], $userBusinessServices),
                self::topic('professionals.coaching', 'coaching', 'אימון אישי / עסקי', 'Coaching', [], $userBusinessServices),
            ]),
            self::group('digital_creators', 'דיגיטל ויוצרים', 'Digital & creators', '#9333ea', [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS], [
                self::topic('creators.photographer', 'photographer', 'צלם', 'Photographer'),
                self::topic('creators.video_editor', 'video-editor', 'וידאו / עריכה', 'Video editing', ['events_entertainment.video']),
                self::topic('creators.content_writer', 'content-writer', 'כותב תוכן', 'Content writer'),
                self::topic('creators.illustrator', 'illustrator', 'מאייר', 'Illustrator'),
                self::topic('creators.artist', 'artist', 'אמן', 'Artist'),
                self::topic('digital_creators.social_media', 'social-media', 'ניהול סושיאל', 'Social media'),
                self::topic('creators.handmade', 'handmade', 'עבודת יד', 'Handmade'),
                self::topic('creators.personalized_gifts', 'personalized-gifts', 'מתנות אישיות', 'Personalized gifts'),
                self::topic('creators.fashion_designer', 'fashion-designer', 'מעצב אופנה', 'Fashion designer'),
                self::topic('creators.jewelry', 'jewelry', 'תכשיטים', 'Jewelry'),
            ]),
            self::group('pets', 'חיות מחמד', 'Pets', '#65a30d', [self::SCOPE_USERS, self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_PRODUCTS], [
                self::topic('professionals.veterinarians', 'veterinarians', 'וטרינרים', 'Veterinarians'),
                self::topic('professionals.pet_grooming', 'pet-grooming', 'מספרות לחיות', 'Pet grooming'),
                self::topic('professionals.dog_training', 'dog-training', 'אילוף כלבים', 'Dog training'),
                self::topic('pets.pet_sitting_walking', 'pet-sitting-walking', 'שמירה והולכת כלבים', 'Pet sitting & walking'),
                self::topic('pets.pet_supplies', 'pet-supplies', 'ציוד ומזון לחיות', 'Pet supplies'),
            ]),
            self::group('travel_leisure', 'טיולים ופנאי', 'Travel & leisure', '#0f766e', [self::SCOPE_BUSINESS_PAGES, self::SCOPE_SERVICES, self::SCOPE_EVENTS], [
                self::topic('travel_transportation.trips_tours', 'trips-tours', 'טיולים וסיורים', 'Trips & tours'),
                self::topic('travel_leisure.hotels_guesthouses', 'hotels-guesthouses', 'מלונות וצימרים', 'Hotels & guesthouses'),
                self::topic('travel_transportation.travel_services', 'travel-agencies', 'סוכנויות נסיעות', 'Travel agencies', ['travel-services']),
                self::topic('travel_leisure.leisure_activities', 'leisure-activities', 'פעילויות פנאי', 'Leisure activities'),
                self::topic('travel_transportation.bus_services', 'bus-services', 'שירותי אוטובוסים', 'Bus services'),
                self::topic('travel_transportation.private_transportation', 'private-transportation', 'הסעות פרטיות', 'Private transportation'),
                self::topic('travel_transportation.group_transportation', 'group-transportation', 'הסעות קבוצתיות', 'Group transportation'),
            ]),
            self::group('community_local', 'קהילה מקומית', 'Local community', '#f97316', $community, [
                self::topic('community_pages.local.neighborhood_group', 'neighborhood-group', 'קבוצת שכונה', 'Neighborhood group'),
                self::topic('community_pages.local.residents', 'local-residents', 'תושבים מקומיים', 'Local residents'),
                self::topic('community_pages.local.building_street', 'building-street-group', 'בניין / רחוב', 'Building / street group'),
                self::topic('community_pages.local.help', 'local-help', 'עזרה מקומית', 'Local help'),
            ]),
            self::group('community_religious', 'קהילה ודת', 'Religious community', '#65a30d', $communityEvents, [
                self::topic('community_religious.synagogues_institutions', 'synagogues-institutions', 'בתי כנסת ומוסדות', 'Synagogues & institutions'),
                self::topic('community_religious.shiurim_torah_classes', 'torah-classes', 'שיעורי תורה', 'Torah classes'),
                self::topic('community_pages.religious.holiday', 'holiday-community', 'קהילת חגים', 'Holiday community'),
                self::topic('community_religious.religious_services', 'religious-services', 'שירותי דת', 'Religious services'),
            ]),
            self::group('community_volunteering_charity', 'התנדבות וצדקה', 'Volunteering & charity', '#16a34a', $communityEvents, [
                self::topic('community_religious.charities', 'charities', 'צדקה ועמותות', 'Charities'),
                self::topic('community_pages.volunteering.volunteering', 'volunteering-groups', 'התנדבות', 'Volunteering'),
                self::topic('community_pages.volunteering.donations', 'donations', 'תרומות', 'Donations'),
                self::topic('community_pages.volunteering.mutual_aid', 'mutual-aid', 'עזרה הדדית', 'Mutual aid'),
            ]),
            self::group('community_families_kids', 'משפחות וילדים', 'Families & kids', '#e11d48', $communityEvents, [
                self::topic('community_pages.families.parent_group', 'parent-group', 'קבוצת הורים', 'Parent group'),
                self::topic('community_pages.families.school_group', 'school-group', 'קבוצת בית ספר', 'School group'),
                self::topic('community_pages.families.youth_movement', 'youth-movement', 'תנועת נוער', 'Youth movement'),
                self::topic('community_pages.families.kids_activities', 'community-kids-activities', 'פעילויות ילדים', 'Kids activities'),
            ]),
            self::group('community_culture_language', 'תרבות ושפות', 'Culture & language', '#9333ea', $communityEvents, [
                self::topic('community_pages.culture.cultural_group', 'cultural-group', 'קבוצה תרבותית', 'Cultural group'),
                self::topic('community_pages.culture.immigrant_group', 'immigrant-group', 'קהילת עולים', 'Immigrant group'),
                self::topic('community_pages.culture.language_group', 'language-group', 'קבוצת שפה', 'Language group'),
                self::topic('community_pages.culture.art_club', 'art-culture-club', 'מועדון אמנות ותרבות', 'Art & culture club'),
            ]),
            self::group('community_sports_wellness', 'ספורט ובריאות בקהילה', 'Sports & wellness', '#0891b2', $communityEvents, [
                self::topic('community_pages.sports.sports_club', 'sports-club', 'מועדון ספורט', 'Sports club'),
                self::topic('community_pages.sports.running_walking', 'running-walking-group', 'קבוצת הליכה / ריצה', 'Walking / running group'),
                self::topic('community_pages.sports.fitness_yoga', 'fitness-yoga-group', 'כושר / יוגה', 'Fitness / yoga group'),
            ]),
            self::group('community_seniors_support', 'ותיקים ותמיכה', 'Seniors & support', '#7c2d12', $communityEvents, [
                self::topic('community_pages.support.seniors_group', 'seniors-group', 'קבוצת ותיקים', 'Seniors group'),
                self::topic('community_pages.support.health_support', 'health-support-group', 'קבוצת תמיכה בריאותית', 'Health support group'),
                self::topic('community_pages.support.care_support', 'care-support-group', 'תמיכה למטפלים', 'Care support group'),
            ]),
            self::group('community_market_exchange', 'שוק והחלפות בקהילה', 'Local market & exchange', '#0284c7', $communityEvents, [
                self::topic('community_pages.market.swap_free', 'swap-free-items', 'החלפות ומסירה', 'Swap / free items'),
                self::topic('community_pages.market.local_market', 'local-market', 'שוק מקומי', 'Local market'),
                self::topic('community_pages.market.community_sales', 'community-sales', 'מכירות קהילתיות', 'Community sales'),
            ]),
            self::group('community_public_municipal', 'ציבורי ועירוני', 'Public & municipal', '#475569', $communityEvents, [
                self::topic('community_pages.public.announcements', 'local-announcements', 'הודעות מקומיות', 'Local announcements'),
                self::topic('community_pages.public.safety_emergency', 'safety-emergency', 'בטיחות וחירום', 'Safety & emergency'),
                self::topic('community_pages.public.public_services', 'public-services', 'שירותים ציבוריים', 'Public services'),
            ]),
            self::group('products_home_garden', 'בית וגן', 'Home & garden', '#0891b2', $products, [
                self::topic('products.home_garden.furniture', 'furniture-products', 'רהיטים', 'Furniture', ['products.home_garden', 'home-garden-products']),
                self::topic('products.home_garden.home_decor', 'home-decor-products', 'עיצוב הבית', 'Home decor'),
                self::topic('products.home_garden.kitchen_dining', 'kitchen-dining-products', 'מטבח ואירוח', 'Kitchen & dining'),
                self::topic('products.home_garden.bedding_textiles', 'bedding-textiles', 'מצעים וטקסטיל', 'Bedding & textiles'),
                self::topic('products.home_garden.lighting', 'lighting-products', 'תאורה', 'Lighting'),
                self::topic('products.home_garden.tools_diy', 'tools-diy', 'כלים ועשה זאת בעצמך', 'Tools & DIY'),
                self::topic('products.home_garden.garden_outdoor', 'garden-outdoor-products', 'גן וחוץ', 'Garden & outdoor'),
                self::topic('products.home_garden.cleaning_supplies', 'cleaning-supplies', 'חומרי ניקוי', 'Cleaning supplies'),
                self::topic('products.home_garden.storage_organization', 'storage-organization', 'אחסון וארגון', 'Storage & organization'),
                self::topic('products.home_garden.grills', 'grills', 'גרילים', 'Grills'),
            ]),
            self::group('products_electronics_computers', 'אלקטרוניקה ומחשבים', 'Electronics & computers', '#475569', $products, [
                self::topic('products.electronics_computers.phones_tablets', 'phones-tablets', 'טלפונים וטאבלטים', 'Phones & tablets', ['products.electronics', 'electronics-products']),
                self::topic('products.electronics_computers.computers_laptops', 'computers-laptops', 'מחשבים ולפטופים', 'Computers & laptops'),
                self::topic('products.electronics_computers.tv_audio', 'tv-audio', 'טלוויזיה ואודיו', 'TV & audio'),
                self::topic('products.electronics_computers.cameras', 'cameras', 'מצלמות', 'Cameras'),
                self::topic('products.electronics_computers.gaming', 'gaming', 'גיימינג', 'Gaming'),
                self::topic('products.electronics_computers.smart_home_security', 'smart-home-security-products', 'בית חכם ואבטחה', 'Smart home & security'),
                self::topic('products.electronics_computers.accessories_cables', 'accessories-cables', 'אביזרים וכבלים', 'Accessories & cables'),
                self::topic('products.electronics_computers.printers_office_tech', 'printers-office-tech', 'מדפסות וציוד משרדי', 'Printers & office tech'),
            ]),
            self::group('products_appliances', 'מוצרי חשמל', 'Appliances', '#64748b', $products, [
                self::topic('products.appliances.refrigerators_freezers', 'refrigerators-freezers', 'מקררים ומקפיאים', 'Refrigerators & freezers'),
                self::topic('products.appliances.ovens_stoves', 'ovens-stoves', 'תנורים וכיריים', 'Ovens & stoves'),
                self::topic('products.appliances.dishwashers', 'dishwashers', 'מדיחי כלים', 'Dishwashers'),
                self::topic('products.appliances.laundry', 'laundry-appliances', 'כביסה ומייבשים', 'Laundry'),
                self::topic('products.appliances.heating_cooling', 'heating-cooling-appliances', 'חימום וקירור', 'Heating & cooling'),
                self::topic('products.appliances.coffee_small_appliances', 'coffee-small-appliances', 'קפה ומכשירים קטנים', 'Coffee & small appliances'),
                self::topic('products.appliances.vacuum_cleaning_devices', 'vacuum-cleaning-devices', 'שואבים ומכשירי ניקוי', 'Vacuum & cleaning devices'),
            ]),
            self::group('products_fashion_beauty', 'אופנה ויופי', 'Fashion & beauty', '#e11d48', $products, [
                self::topic('products.fashion_beauty.women_clothing', 'women-clothing', 'בגדי נשים', 'Women clothing', ['products.fashion_beauty', 'fashion-beauty-products']),
                self::topic('products.fashion_beauty.men_clothing', 'men-clothing', 'בגדי גברים', 'Men clothing'),
                self::topic('products.fashion_beauty.kids_clothing', 'kids-clothing', 'בגדי ילדים', 'Kids clothing'),
                self::topic('products.fashion_beauty.shoes', 'shoes', 'נעליים', 'Shoes'),
                self::topic('products.fashion_beauty.bags_accessories', 'bags-accessories', 'תיקים ואביזרים', 'Bags & accessories'),
                self::topic('products.fashion_beauty.jewelry_watches', 'jewelry-watches-products', 'תכשיטים ושעונים', 'Jewelry & watches'),
                self::topic('products.fashion_beauty.wigs_head_coverings', 'wigs-head-coverings', 'פאות וכיסויי ראש', 'Wigs & head coverings'),
                self::topic('products.fashion_beauty.cosmetics_skin_care', 'cosmetics-skin-care-products', 'קוסמטיקה וטיפוח', 'Cosmetics & skin care'),
                self::topic('products.fashion_beauty.perfume', 'perfume', 'בשמים', 'Perfume'),
                self::topic('products.fashion_beauty.hair_nail_products', 'hair-nail-products', 'מוצרי שיער וציפורניים', 'Hair & nail products'),
            ]),
            self::group('products_kids_baby', 'ילדים ותינוקות', 'Kids & baby', '#f59e0b', $products, [
                self::topic('products.kids_baby.strollers_car_seats', 'strollers-car-seats', 'עגלות וכיסאות בטיחות', 'Strollers & car seats', ['products.kids_baby', 'kids-baby-products']),
                self::topic('products.kids_baby.baby_furniture', 'baby-furniture', 'ריהוט לתינוקות', 'Baby furniture'),
                self::topic('products.kids_baby.toys_games', 'toys-games', 'צעצועים ומשחקים', 'Toys & games'),
                self::topic('products.kids_baby.school_supplies', 'school-supplies-products', 'ציוד לבית ספר', 'School supplies'),
                self::topic('products.kids_baby.baby_clothing', 'baby-clothing', 'בגדי תינוקות', 'Baby clothing'),
                self::topic('products.kids_baby.feeding_care', 'feeding-care', 'האכלה וטיפול', 'Feeding & care'),
            ]),
            self::group('products_food_grocery', 'מזון ומכולת', 'Food & grocery', '#ef4444', $products, [
                self::topic('products.food_grocery.groceries_pantry', 'groceries-pantry', 'מכולת ומזווה', 'Groceries & pantry', ['products.food_grocery', 'food-grocery-products']),
                self::topic('products.food_grocery.bakery', 'bakery-products', 'מאפים', 'Bakery'),
                self::topic('products.food_grocery.prepared_food', 'prepared-food-products', 'אוכל מוכן', 'Prepared food'),
                self::topic('products.food_grocery.meat_fish_deli', 'meat-fish-deli', 'בשר, דגים ומעדנייה', 'Meat, fish & deli'),
                self::topic('products.food_grocery.fruit_veg', 'fruit-vegetables', 'פירות וירקות', 'Fruit & veg'),
                self::topic('products.food_grocery.kosher_specialty', 'kosher-specialty-products', 'כשר ומיוחד', 'Kosher & specialty'),
                self::topic('products.food_grocery.drinks_wine', 'drinks-wine', 'משקאות ויין', 'Drinks & wine'),
            ]),
            self::group('products_sports_leisure', 'ספורט ופנאי', 'Sports & leisure', '#0284c7', $products, [
                self::topic('products.sports_leisure.fitness_equipment', 'fitness-equipment', 'ציוד כושר', 'Fitness equipment', ['products.sports_leisure', 'sports-leisure-products']),
                self::topic('products.sports_leisure.bikes_scooters', 'bikes-scooters-products', 'אופניים וקורקינטים', 'Bikes & scooters'),
                self::topic('products.sports_leisure.outdoor_camping', 'outdoor-camping', 'קמפינג וחוץ', 'Outdoor & camping'),
                self::topic('products.sports_leisure.sportswear', 'sportswear', 'ביגוד ספורט', 'Sportswear'),
                self::topic('products.sports_leisure.hobbies_crafts', 'hobbies-crafts', 'תחביבים ויצירה', 'Hobbies & crafts'),
                self::topic('products.sports_leisure.books_media', 'books-media', 'ספרים ומדיה', 'Books & media'),
                self::topic('products.sports_leisure.musical_instruments', 'musical-instruments', 'כלי נגינה', 'Musical instruments'),
            ]),
            self::group('products_gifts_handmade', 'מתנות ועבודת יד', 'Gifts & handmade', '#9333ea', $products, [
                self::topic('products.gifts_handmade.flowers', 'flowers', 'פרחים', 'Flowers', ['products.gifts_handmade', 'gifts-handmade-products']),
                self::topic('products.gifts_handmade.handmade_items', 'handmade-items', 'עבודת יד', 'Handmade items'),
                self::topic('products.gifts_handmade.personalized_gifts', 'personalized-gifts-products', 'מתנות אישיות', 'Personalized gifts'),
                self::topic('products.gifts_handmade.art', 'art-products', 'אמנות', 'Art'),
                self::topic('products.gifts_handmade.judaica', 'judaica', 'יודאיקה', 'Judaica'),
                self::topic('products.gifts_handmade.party_supplies', 'party-supplies', 'ציוד למסיבות', 'Party supplies'),
            ]),
            self::group('products_pets', 'מוצרים לחיות', 'Pet products', '#65a30d', $products, [
                self::topic('products.pets.food', 'pet-food', 'מזון לחיות', 'Pet food', ['products.pets', 'pet-products']),
                self::topic('products.pets.accessories', 'pet-accessories', 'אביזרים לחיות', 'Pet accessories'),
                self::topic('products.pets.aquariums', 'aquariums', 'אקווריומים', 'Aquariums'),
                self::topic('products.pets.bird_small_pet_supplies', 'bird-small-pet-supplies', 'ציוד לציפורים וחיות קטנות', 'Bird & small pet supplies'),
            ]),
            self::group('products_cars_accessories', 'רכב ואביזרים', 'Cars & accessories', '#7c2d12', $products, [
                self::topic('products.cars_accessories.car_accessories', 'car-accessory-products', 'אביזרי רכב', 'Car accessories', ['products.cars_accessories']),
                self::topic('products.cars_accessories.car_electronics', 'car-electronics', 'אלקטרוניקה לרכב', 'Car electronics'),
                self::topic('products.cars_accessories.tires_wheels', 'tires-wheels', 'צמיגים וג׳נטים', 'Tires & wheels'),
                self::topic('products.cars_accessories.tools', 'car-tools', 'כלים לרכב', 'Tools'),
                self::topic('products.cars_accessories.bike_scooter_parts', 'bike-scooter-parts', 'חלקי אופניים וקורקינט', 'Bike & scooter parts'),
            ]),
            self::group('products_office_business', 'משרד ועסקים', 'Office & business', '#0f766e', $products, [
                self::topic('products.office_business.office_furniture', 'office-furniture', 'ריהוט משרדי', 'Office furniture', ['products.office_business', 'office-business-products']),
                self::topic('products.office_business.stationery', 'stationery', 'ציוד משרדי', 'Stationery'),
                self::topic('products.office_business.toner_printers', 'toner-printers', 'טונרים ומדפסות', 'Toner & printers'),
                self::topic('products.office_business.packaging', 'packaging', 'אריזות', 'Packaging'),
                self::topic('products.office_business.professional_equipment', 'professional-equipment', 'ציוד מקצועי', 'Professional equipment'),
            ]),
            self::group('services_cleaning_moving', 'ניקיון והובלות', 'Cleaning & moving', '#f97316', $services, [
                self::topic('services.cleaning_moving.house_cleaning', 'house-cleaning', 'ניקיון בתים', 'House cleaning', ['services.cleaning_moving', 'cleaning-moving-services']),
                self::topic('services.cleaning_moving.office_cleaning', 'office-cleaning', 'ניקיון משרדים', 'Office cleaning'),
                self::topic('services.cleaning_moving.floor_polish', 'floor-polish', 'פוליש לרצפה', 'Floor polish'),
                self::topic('services.cleaning_moving.moving', 'moving-services', 'הובלות', 'Moving'),
                self::topic('services.cleaning_moving.packing', 'packing-services', 'אריזה', 'Packing'),
                self::topic('services.cleaning_moving.storage', 'storage-services', 'אחסון', 'Storage'),
                self::topic('services.cleaning_moving.waste_removal_recycling', 'waste-removal-recycling', 'פינוי ומחזור פסולת', 'Waste removal & recycling'),
            ]),
            self::group('services_beauty_wellness', 'יופי ובריאות', 'Beauty & wellness services', '#e11d48', $services, [
                self::topic('services.beauty_wellness.hair', 'hair-services', 'שיער', 'Hair', ['services.beauty_wellness', 'beauty-wellness-services']),
                self::topic('services.beauty_wellness.makeup', 'makeup-services', 'איפור', 'Makeup'),
                self::topic('services.beauty_wellness.nails', 'nail-services', 'ציפורניים', 'Nails'),
                self::topic('services.beauty_wellness.skin_care', 'skin-care-services', 'טיפוח עור', 'Skin care'),
                self::topic('services.beauty_wellness.massage_spa', 'massage-spa-services', 'עיסוי וספא', 'Massage & spa'),
                self::topic('services.beauty_wellness.personal_training', 'personal-training-services', 'אימון אישי', 'Personal training'),
                self::topic('services.beauty_wellness.nutrition', 'nutrition-services', 'תזונה', 'Nutrition'),
                self::topic('services.beauty_wellness.alternative_medicine', 'alternative-medicine-services', 'רפואה משלימה', 'Alternative medicine'),
            ]),
            self::group('services_health_care', 'בריאות וטיפול', 'Health & care services', '#16a34a', $services, [
                self::topic('services.health_care.physiotherapy', 'physiotherapy-services', 'פיזיותרפיה', 'Physiotherapy', ['services.health_care', 'health-care-services']),
                self::topic('services.health_care.therapy_counseling', 'therapy-counseling-services', 'טיפול וייעוץ', 'Therapy & counseling'),
                self::topic('services.health_care.nursing_caregiver', 'nursing-caregiver', 'סיעוד ומטפלים', 'Nursing & caregiver'),
                self::topic('services.health_care.senior_care', 'senior-care-services', 'טיפול בקשישים', 'Senior care'),
                self::topic('services.health_care.medical_massage', 'medical-massage-services', 'עיסוי רפואי', 'Medical massage'),
                self::topic('services.health_care.medical_equipment_service', 'medical-equipment-service', 'שירות לציוד רפואי', 'Medical equipment service'),
            ]),
            self::group('services_education_lessons', 'שיעורים ולימודים', 'Education & lessons', '#7c3aed', $services, [
                self::topic('services.education_lessons.private_tutors', 'private-tutor-services', 'מורים פרטיים', 'Private tutors', ['services.education_lessons', 'education-lesson-services']),
                self::topic('services.education_lessons.language_lessons', 'language-lesson-services', 'שיעורי שפה', 'Language lessons'),
                self::topic('services.education_lessons.music_lessons', 'music-lesson-services', 'שיעורי נגינה', 'Music lessons'),
                self::topic('services.education_lessons.courses_workshops', 'course-workshop-services', 'קורסים וסדנאות', 'Courses & workshops'),
                self::topic('services.education_lessons.driving_lessons', 'driving-lesson-services', 'שיעורי נהיגה', 'Driving lessons'),
                self::topic('services.education_lessons.kids_activities', 'kids-activity-services', 'פעילויות ילדים', 'Kids activities'),
                self::topic('services.education_lessons.religious_studies', 'religious-study-services', 'לימודי קודש', 'Religious studies'),
            ]),
            self::group('services_events_entertainment', 'אירועים ובידור', 'Events & entertainment services', '#f54291', $services, [
                self::topic('services.events_entertainment.event_production', 'event-production-services', 'הפקת אירועים', 'Event production', ['services.events_entertainment', 'event-entertainment-services']),
                self::topic('services.events_entertainment.dj_music', 'dj-music-services', 'DJ ומוזיקה', 'DJ & music'),
                self::topic('services.events_entertainment.photography_video', 'photography-video-services', 'צילום ווידאו', 'Photography & video'),
                self::topic('services.events_entertainment.catering', 'catering-services', 'קייטרינג', 'Catering'),
                self::topic('services.events_entertainment.venues', 'venue-services', 'אולמות ומקומות', 'Venues'),
                self::topic('services.events_entertainment.party_equipment', 'party-equipment-services', 'ציוד לאירועים', 'Party equipment'),
                self::topic('services.events_entertainment.attractions', 'event-attraction-services', 'אטרקציות', 'Attractions'),
                self::topic('services.events_entertainment.kids_entertainer', 'kids-entertainer-services', 'מפעיל ילדים', 'Kids entertainer'),
                self::topic('services.events_entertainment.flowers_decor', 'event-decor-services', 'פרחים ועיצוב', 'Flowers & decor'),
            ]),
            self::group('services_business_professional', 'עסקים ומקצועי', 'Business & professional services', '#7c2d12', $services, [
                self::topic('services.business_professional.accounting_tax', 'accounting-tax-services', 'חשבונאות ומסים', 'Accounting & tax', ['services.business_finance', 'business-finance-services']),
                self::topic('services.business_professional.law', 'law-services', 'משפטי', 'Law', ['services.legal_professional', 'legal-professional-services']),
                self::topic('services.business_professional.insurance', 'insurance-services', 'ביטוח', 'Insurance'),
                self::topic('services.business_professional.real_estate_brokerage', 'real-estate-brokerage', 'תיווך נדל״ן', 'Real estate brokerage'),
                self::topic('services.business_professional.marketing_seo', 'marketing-seo-services', 'שיווק ו-SEO', 'Marketing & SEO'),
                self::topic('services.business_professional.graphic_design_branding', 'graphic-design-branding', 'עיצוב גרפי ומיתוג', 'Graphic design & branding'),
                self::topic('services.business_professional.web_it_support', 'web-it-support', 'אתרים ותמיכת IT', 'Web & IT support'),
                self::topic('services.business_professional.translation', 'translation-services', 'תרגום', 'Translation'),
                self::topic('services.business_professional.consulting_coaching', 'consulting-coaching', 'ייעוץ ואימון', 'Consulting & coaching'),
                self::topic('services.business_professional.office_admin', 'office-admin-services', 'אדמיניסטרציה ומשרד', 'Office & admin'),
            ]),
            self::group('services_transportation', 'תחבורה והסעות', 'Transportation services', '#0284c7', $services, [
                self::topic('services.transportation.shuttles_taxis', 'shuttles-taxis-services', 'הסעות ומוניות', 'Shuttles & taxis', ['services.transportation', 'transportation-services']),
                self::topic('services.transportation.courier', 'courier-services', 'שליחויות', 'Courier'),
                self::topic('services.transportation.car_rental_leasing', 'car-rental-leasing-services', 'השכרה וליסינג', 'Car rental & leasing'),
                self::topic('services.transportation.garage_repair', 'garage-repair-services', 'מוסך ותיקונים', 'Garage & repair'),
                self::topic('services.transportation.towing_roadside', 'towing-roadside-services', 'גרירה וסיוע בדרכים', 'Towing & roadside'),
                self::topic('services.transportation.tours_trips', 'tours-trips-services', 'טיולים וסיורים', 'Tours & trips'),
            ]),
            self::group('services_pets', 'שירותים לחיות', 'Pet services', '#65a30d', $services, [
                self::topic('services.pets.grooming', 'pet-grooming-services', 'טיפוח וספרות', 'Grooming', ['services.pets', 'pet-services']),
                self::topic('services.pets.veterinary', 'veterinary-services', 'וטרינריה', 'Veterinary'),
                self::topic('services.pets.dog_training', 'dog-training-services', 'אילוף כלבים', 'Dog training'),
                self::topic('services.pets.pet_sitting_walking', 'pet-sitting-walking-services', 'שמירה והולכה', 'Pet sitting & walking'),
            ]),
            self::group('services_creative_digital', 'יצירה ודיגיטל', 'Creative & digital services', '#9333ea', $services, [
                self::topic('services.creative_digital.content_writing', 'content-writing-services', 'כתיבת תוכן', 'Content writing'),
                self::topic('services.creative_digital.video_editing', 'video-editing-services', 'עריכת וידאו', 'Video editing'),
                self::topic('services.creative_digital.photography', 'photography-services', 'צילום', 'Photography'),
                self::topic('services.creative_digital.illustration_art', 'illustration-art-services', 'איור ואמנות', 'Illustration & art'),
                self::topic('services.creative_digital.social_media', 'social-media-services', 'ניהול סושיאל', 'Social media'),
                self::topic('services.creative_digital.handmade_custom_design', 'handmade-custom-design', 'עיצוב אישי ועבודת יד', 'Handmade & custom design'),
            ]),
            self::group('events_community_social', 'קהילה וחברה', 'Community & social events', '#f97316', $events, [
                self::topic('events.community_social.neighborhood_meeting', 'neighborhood-meeting', 'מפגש שכונתי', 'Neighborhood meeting', ['events.community', 'community-events-local']),
                self::topic('events.community_social.community_festival', 'community-festival', 'פסטיבל קהילתי', 'Community festival'),
                self::topic('events.community_social.charity_fundraiser', 'charity-fundraiser', 'צדקה וגיוס תרומות', 'Charity / fundraiser'),
                self::topic('events.community_social.volunteering', 'volunteering-events', 'התנדבות', 'Volunteering'),
                self::topic('events.community_social.local_market_swap', 'local-market-swap', 'שוק / החלפות', 'Local market / swap'),
                self::topic('events.community_social.public_meeting', 'public-meeting', 'אספה ציבורית', 'Public meeting'),
            ]),
            self::group('events_kids_family', 'ילדים ומשפחה', 'Kids & family events', '#e11d48', $events, [
                self::topic('events.kids_family.kids_show', 'kids-show', 'מופע ילדים', 'Kids show', ['events.kids_family', 'kids-family-events']),
                self::topic('events.kids_family.family_activity', 'family-activity', 'פעילות משפחתית', 'Family activity'),
                self::topic('events.kids_family.story_time', 'story-time', 'שעת סיפור', 'Story time'),
                self::topic('events.kids_family.camps', 'kids-camps', 'קייטנות', 'Camps'),
                self::topic('events.kids_family.parenting_event', 'parenting-event', 'אירוע הורות', 'Parenting event'),
            ]),
            self::group('events_classes_workshops', 'שיעורים וסדנאות', 'Classes & workshops', '#7c3aed', $events, [
                self::topic('events.classes_workshops.lecture', 'lectures', 'הרצאה', 'Lecture', ['events.classes_workshops', 'classes-workshops']),
                self::topic('events.classes_workshops.course', 'courses', 'קורס', 'Course'),
                self::topic('events.classes_workshops.art_craft_workshop', 'art-craft-workshop', 'סדנת יצירה', 'Art / craft workshop'),
                self::topic('events.classes_workshops.cooking_workshop', 'cooking-workshop', 'סדנת בישול', 'Cooking workshop'),
                self::topic('events.classes_workshops.language_class', 'language-class', 'שיעור שפה', 'Language class'),
                self::topic('events.classes_workshops.professional_workshop', 'professional-workshop', 'סדנה מקצועית', 'Professional workshop'),
                self::topic('events.classes_workshops.torah_religious_class', 'torah-religious-class', 'שיעור תורה / דת', 'Torah / religious class'),
            ]),
            self::group('events_music_shows', 'מוזיקה והופעות', 'Music & shows', '#f54291', $events, [
                self::topic('events.music_shows.concert', 'concerts', 'הופעה חיה', 'Concert', ['events.music_shows', 'music-shows']),
                self::topic('events.music_shows.dj_party', 'dj-party', 'מסיבת DJ', 'DJ / party'),
                self::topic('events.music_shows.theater', 'theater', 'תיאטרון', 'Theater'),
                self::topic('events.music_shows.standup', 'standup', 'סטנדאפ', 'Standup'),
                self::topic('events.music_shows.dance', 'dance-show', 'מחול', 'Dance'),
                self::topic('events.music_shows.open_mic', 'open-mic', 'במה פתוחה', 'Open mic'),
            ]),
            self::group('events_markets_sales', 'שווקים ומכירות', 'Markets & sales', '#0891b2', $events, [
                self::topic('events.markets_sales.garage_sale', 'garage-sale', 'מכירת חצר', 'Garage sale', ['events.markets_sales', 'markets-sales']),
                self::topic('events.markets_sales.pop_up_shop', 'pop-up-shop', 'פופ-אפ', 'Pop-up shop'),
                self::topic('events.markets_sales.food_fair', 'food-fair', 'יריד אוכל', 'Food fair'),
                self::topic('events.markets_sales.craft_fair', 'craft-fair', 'יריד יצירה', 'Craft fair'),
                self::topic('events.markets_sales.holiday_market', 'holiday-market', 'שוק חג', 'Holiday market'),
            ]),
            self::group('events_religious_jewish', 'דת ויהדות', 'Religious & Jewish events', '#65a30d', $events, [
                self::topic('events.religious_jewish.shiur', 'shiur', 'שיעור תורה', 'Shiur', ['events.religious', 'religious-events']),
                self::topic('events.religious_jewish.synagogue_event', 'synagogue-event', 'אירוע בית כנסת', 'Synagogue event'),
                self::topic('events.religious_jewish.holiday_event', 'holiday-event', 'אירוע חג', 'Holiday event'),
                self::topic('events.religious_jewish.shabbat_meal', 'shabbat-meal', 'סעודת שבת', 'Shabbat meal'),
                self::topic('events.religious_jewish.community_celebration', 'community-celebration', 'שמחה קהילתית', 'Community celebration'),
            ]),
            self::group('events_sports_fitness', 'ספורט וכושר', 'Sports & fitness events', '#0284c7', $events, [
                self::topic('events.sports_fitness.group_workout', 'group-workout', 'אימון קבוצתי', 'Group workout', ['events.sports', 'sports-events']),
                self::topic('events.sports_fitness.run_walk', 'run-walk', 'ריצה / הליכה', 'Run / walk'),
                self::topic('events.sports_fitness.tournament', 'tournament', 'טורניר', 'Tournament'),
                self::topic('events.sports_fitness.yoga_pilates', 'yoga-pilates', 'יוגה / פילאטיס', 'Yoga / Pilates'),
                self::topic('events.sports_fitness.outdoor_activity', 'outdoor-activity', 'פעילות חוץ', 'Outdoor activity'),
            ]),
            self::group('events_business_networking', 'עסקים ונטוורקינג', 'Business & networking events', '#7c2d12', $events, [
                self::topic('events.business_networking.networking', 'networking', 'נטוורקינג', 'Networking', ['events.business_networking', 'business-networking-events']),
                self::topic('events.business_networking.meetup', 'meetup', 'מיטאפ', 'Meetup'),
                self::topic('events.business_networking.seminar', 'seminar', 'סמינר', 'Seminar'),
                self::topic('events.business_networking.business_workshop', 'business-workshop', 'סדנה עסקית', 'Business workshop'),
                self::topic('events.business_networking.expo', 'expo', 'תערוכה / אקספו', 'Expo'),
            ]),
            self::group('events_food_culture', 'אוכל ותרבות', 'Food & culture events', '#ef4444', $events, [
                self::topic('events.food_culture.tasting', 'tasting', 'טעימות', 'Tasting', ['events.food_culture', 'food-culture-events']),
                self::topic('events.food_culture.restaurant_event', 'restaurant-event', 'אירוע מסעדה', 'Restaurant event'),
                self::topic('events.food_culture.cultural_evening', 'cultural-evening', 'ערב תרבות', 'Cultural evening'),
                self::topic('events.food_culture.exhibition', 'exhibition', 'תערוכה', 'Exhibition'),
                self::topic('events.food_culture.film', 'film-event', 'סרט', 'Film'),
            ]),
            self::group('events_online_hybrid', 'אונליין והיברידי', 'Online & hybrid events', '#475569', $events, [
                self::topic('events.online_hybrid.webinar', 'webinar', 'וובינר', 'Webinar'),
                self::topic('events.online_hybrid.online_class', 'online-class', 'שיעור אונליין', 'Online class'),
                self::topic('events.online_hybrid.live_stream', 'live-stream', 'שידור חי', 'Live stream'),
            ]),
        ];

        return self::$groups;
    }

    private static function group(string $key, string $he, string $en, string $color, array $scopes, array $topics, ?string $ru = null, ?string $fr = null): array
    {
        return [
            'key' => $key,
            'labels' => self::labels($he, $en, $ru, $fr),
            'color' => $color,
            'scopes' => $scopes,
            'topics' => $topics,
        ];
    }

    private static function topic(string $key, string $slug, string $he, string $en, array $aliases = [], ?array $scopes = null, ?string $ru = null, ?string $fr = null): array
    {
        return [
            'key' => $key,
            'slug' => $slug,
            'labels' => self::labels($he, $en, $ru, $fr),
            'aliases' => $aliases,
            'scopes' => $scopes,
        ];
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
        return [
            'key' => $topic['key'],
            'slug' => $topic['slug'],
            'labels' => $topic['labels'],
            'color' => $topic['color'] ?? $group['color'],
            'group_key' => $group['key'],
            'group_labels' => $group['labels'],
            'scopes' => $topic['scopes'] ?? $group['scopes'],
            'aliases' => collect(Arr::wrap($topic['aliases'] ?? []))->unique()->values()->all(),
        ];
    }
}
