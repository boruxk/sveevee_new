<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageProduct;
use App\Support\CatalogTopics;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SeoPrerenderService
{
    private const LOCALES = ['he', 'en', 'ru', 'fr'];
    private const RTL_LOCALES = ['he'];
    private const MANIFEST = '.sveevee-prerender.json';
    private const SCHEMA_WEEKDAYS = [
        'sunday' => 'Sunday',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
    ];

    private const COPY = [
        'he' => [
            'site' => 'Sveevee',
            'businessTitle' => '{name} ב{city} - שירותים, ביקורות ויצירת קשר | Sveevee',
            'businessDescription' => '{name} - {category} ב{city}{neighborhood}. צפו בשירותים, שעות פעילות, אזורי שירות, ביקורות ופרטי יצירת קשר ב-Sveevee.',
            'productTitle' => '{name} ב{city} - מחיר ופרטי המוצר | Sveevee',
            'productDescription' => '{name} ב{city} במחיר {price}. צפו בפרטי המוצר, המוכר, המיקום, הזמינות ואפשרויות יצירת קשר ב-Sveevee.',
            'category' => 'קטגוריה',
            'location' => 'מיקום',
            'contact' => 'יצירת קשר',
            'phone' => 'טלפון',
            'email' => 'אימייל',
            'openingHours' => 'שעות פעילות',
            'rating' => 'ביקורות',
            'products' => 'מוצרים',
            'priceList' => 'מחירון',
            'services' => 'שירותים',
            'seller' => 'מוכר',
            'price' => 'מחיר',
            'normalPrice' => 'מחיר רגיל',
            'brand' => 'מותג',
            'model' => 'דגם',
            'availability' => 'זמינות',
            'available' => 'זמין',
            'description' => 'תיאור',
            'businessFallback' => 'עסק מקומי',
        ],
        'en' => [
            'site' => 'Sveevee',
            'businessTitle' => '{name} in {city} - services, reviews, and contact | Sveevee',
            'businessDescription' => '{name} - {category} in {city}{neighborhood}. View services, opening hours, ratings, and contact details on Sveevee.',
            'productTitle' => '{name} in {city} - price and product details | Sveevee',
            'productDescription' => '{name} in {city} for {price}. View product details, seller, location, availability, and contact options on Sveevee.',
            'category' => 'Category',
            'location' => 'Location',
            'contact' => 'Contact',
            'phone' => 'Phone',
            'email' => 'Email',
            'openingHours' => 'Opening hours',
            'rating' => 'Reviews',
            'products' => 'Products',
            'priceList' => 'Price list',
            'services' => 'Services',
            'seller' => 'Seller',
            'price' => 'Price',
            'normalPrice' => 'Normal price',
            'brand' => 'Brand',
            'model' => 'Model',
            'availability' => 'Availability',
            'available' => 'Available',
            'description' => 'Description',
            'businessFallback' => 'Local business',
        ],
        'ru' => [
            'site' => 'Sveevee',
            'businessTitle' => '{name} в {city} - услуги, отзывы и контакт | Sveevee',
            'businessDescription' => '{name} - {category} в {city}{neighborhood}. Смотрите услуги, часы работы, рейтинги и контактные данные в Sveevee.',
            'productTitle' => '{name} в {city} - цена и детали товара | Sveevee',
            'productDescription' => '{name} в {city} за {price}. Смотрите детали товара, продавца, местоположение, наличие и варианты связи в Sveevee.',
            'category' => 'Категория',
            'location' => 'Место',
            'contact' => 'Контакт',
            'phone' => 'Телефон',
            'email' => 'Email',
            'openingHours' => 'Часы работы',
            'rating' => 'Отзывы',
            'products' => 'Товары',
            'priceList' => 'Прайс-лист',
            'services' => 'Услуги',
            'seller' => 'Продавец',
            'price' => 'Цена',
            'normalPrice' => 'Обычная цена',
            'brand' => 'Бренд',
            'model' => 'Модель',
            'availability' => 'Наличие',
            'available' => 'Доступно',
            'description' => 'Описание',
            'businessFallback' => 'Местный бизнес',
        ],
        'fr' => [
            'site' => 'Sveevee',
            'businessTitle' => '{name} a {city} - services, avis et contact | Sveevee',
            'businessDescription' => '{name} - {category} a {city}{neighborhood}. Consultez services, horaires, notes et coordonnees sur Sveevee.',
            'productTitle' => '{name} a {city} - prix et details du produit | Sveevee',
            'productDescription' => '{name} a {city} pour {price}. Consultez details du produit, vendeur, lieu, disponibilite et options de contact sur Sveevee.',
            'category' => 'Categorie',
            'location' => 'Lieu',
            'contact' => 'Contact',
            'phone' => 'Telephone',
            'email' => 'Email',
            'openingHours' => 'Horaires',
            'rating' => 'Avis',
            'products' => 'Produits',
            'priceList' => 'Liste de prix',
            'services' => 'Services',
            'seller' => 'Vendeur',
            'price' => 'Prix',
            'normalPrice' => 'Prix normal',
            'brand' => 'Marque',
            'model' => 'Modele',
            'availability' => 'Disponibilite',
            'available' => 'Disponible',
            'description' => 'Description',
            'businessFallback' => 'Entreprise locale',
        ],
    ];

    public function render(?string $distPath = null): array
    {
        $dist = $this->resolveDistPath($distPath);
        $indexPath = $dist.DIRECTORY_SEPARATOR.'index.html';

        if (! File::exists($indexPath)) {
            throw new RuntimeException("Built frontend index.html was not found at {$indexPath}. Run npm run build first.");
        }

        $indexHtml = File::get($indexPath);
        $this->cleanPreviousFiles($dist);

        $files = [];
        $marketingPages = 0;
        $catalogHubs = 0;
        $businessPages = 0;
        $productPages = 0;

        collect($this->marketingPages())->each(function (array $page) use ($dist, $indexHtml, &$files, &$marketingPages): void {
            $marketingPages++;
            $files[] = $this->writePage($dist, $page['path'], $this->marketingHtml($indexHtml, $page));
        });

        collect(CatalogTopics::scopeHubs())->each(function (array $hub) use ($dist, $indexHtml, &$files, &$catalogHubs): void {
            $catalogHubs++;
            $files[] = $this->writePage($dist, $hub['path'], $this->catalogHubHtml($indexHtml, $hub, 'he'));
        });

        Page::query()
            ->with(['prices', 'products', 'services', 'user.profile'])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->where('type', Page::TYPE_BUSINESS)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get()
            ->each(function (Page $page) use ($dist, $indexHtml, &$files, &$businessPages): void {
                $businessPages++;

                foreach (self::LOCALES as $locale) {
                    $path = $this->businessPath($page, $locale);
                    $files[] = $this->writePage($dist, $path, $this->businessHtml($indexHtml, $page, $locale));
                }
            });

        PageProduct::query()
            ->with([
                'page' => fn ($query) => $query
                    ->with(['user.profile'])
                    ->withCount('ratings')
                    ->withAvg('ratings', 'rating'),
            ])
            ->whereHas('page', function ($query): void {
                $query
                    ->where('type', Page::TYPE_BUSINESS)
                    ->whereHas('user', fn ($user) => $user->whereNull('banned_at'));
            })
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('id')
            ->get()
            ->each(function (PageProduct $product) use ($dist, $indexHtml, &$files, &$productPages): void {
                if (! $product->page) {
                    return;
                }

                $productPages++;

                foreach (self::LOCALES as $locale) {
                    $path = $this->productPath($product, $locale);
                    $files[] = $this->writePage($dist, $path, $this->productHtml($indexHtml, $product, $locale));
                }
            });

        File::put($dist.DIRECTORY_SEPARATOR.self::MANIFEST, json_encode([
            'generated_at' => now()->toISOString(),
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'dist' => $dist,
            'marketing_pages' => $marketingPages,
            'catalog_hubs' => $catalogHubs,
            'business_pages' => $businessPages,
            'product_pages' => $productPages,
            'files' => count($files),
        ];
    }

    private function businessHtml(string $indexHtml, Page $page, string $locale): string
    {
        $meta = $this->businessMeta($page, $locale);

        return $this->decorateIndex($indexHtml, $meta, $this->businessBody($page, $locale, $meta), [
            $this->businessSchema($page, $locale, $meta),
            $this->breadcrumbSchema($meta['breadcrumbs']),
        ]);
    }

    private function productHtml(string $indexHtml, PageProduct $product, string $locale): string
    {
        $meta = $this->productMeta($product, $locale);

        return $this->decorateIndex($indexHtml, $meta, $this->productBody($product, $locale, $meta), [
            $this->productSchema($product, $locale, $meta),
            $this->breadcrumbSchema($meta['breadcrumbs']),
        ]);
    }

    private function catalogHubHtml(string $indexHtml, array $hub, string $locale): string
    {
        $meta = $this->catalogHubMeta($hub, $locale);

        return $this->decorateIndex($indexHtml, $meta, $this->catalogHubBody($hub, $locale, $meta), [
            $this->catalogHubSchema($meta),
            $this->catalogHubItemListSchema($hub, $locale),
            $this->breadcrumbSchema($meta['breadcrumbs']),
        ]);
    }

    private function marketingPages(): array
    {
        return [
            [
                'path' => '/businesses',
                'label' => 'עמוד עסק חינמי',
                'title' => 'עמוד עסק חינמי לפרסום מקומי | Sveevee',
                'description' => 'צרו עמוד עסק חינמי ב-Sveevee עם מוצרים, שירותים, מודעות מקומיות, דירוגים, קישורי קשר וטקסט מוכן ל-SEO לפי עיר ושכונה.',
                'image' => '/assets/landing/promo-business-hero-1360.v3.webp',
                'free' => 'חינם ב-Sveevee, ללא תשלום חודשי וללא דמי הקמה.',
                'sections' => [
                    [
                        'title' => 'מה כולל עמוד עסק',
                        'items' => [
                            'עמוד ציבורי עם לוגו, באנר, תיאור, קטגוריה, עיר, שכונה, כתובת ושעות פתיחה.',
                            'מוצרים עם תמונה, מחיר, תיאור, זמינות וקישור לקנייה באתר העסק.',
                            'שירותים, מודעות עסק, דירוגים, ביקורות, WhatsApp, טלפון, אימייל, מפות וקישורים חברתיים.',
                        ],
                    ],
                    [
                        'title' => 'למה זה טוב לפרסום מקומי',
                        'items' => [
                            'העמוד עוזר ללקוחות להבין את העסק מעבר למודעה קצרה.',
                            'טקסט גלוי, קטגוריה ומיקום עוזרים למנועי חיפוש להבין את העסק.',
                            'אפשר לשתף קישור ישיר או QR ולהביא לקוחות לעמוד אחד ברור.',
                        ],
                    ],
                ],
            ],
            [
                'path' => '/communities',
                'label' => 'עמוד קהילה חינמי',
                'title' => 'עמוד קהילה חינמי לאירועים ועדכונים | Sveevee',
                'description' => 'צרו עמוד קהילה חינמי ב-Sveevee עם אירועים, עדכונים, מודעות מקומיות, דירוגים, קישורים חברתיים ויצירת קשר ישירה.',
                'image' => '/assets/landing/promo-community-hero-1360.v3.webp',
                'free' => 'חינם ב-Sveevee, כולל כל פונקציות הקהילה.',
                'sections' => [
                    [
                        'title' => 'מה כולל עמוד קהילה',
                        'items' => [
                            'עמוד ציבורי עם לוגו, באנר, תיאור, עיר, שכונה, פרטי קשר וקישורים חברתיים.',
                            'אירועים עם תמונה, תאריך, שעת התחלה, שעת סיום, תיאור וכתובת שנפתחת במפה.',
                            'מודעות קהילה, עדכונים, דירוגים, ביקורות, WhatsApp, טלפון, אימייל וצאט.',
                        ],
                    ],
                    [
                        'title' => 'למה זה טוב לקהילות',
                        'items' => [
                            'אנשים יכולים למצוא קבוצות, אירועים ועדכונים לפי עיר, שכונה ונושא.',
                            'כל המידע הציבורי נשאר במקום אחד שקל לשתף.',
                            'מבקרים יכולים להבין את הקהילה לפני הרשמה וליצור קשר כשזה מתאים.',
                        ],
                    ],
                ],
            ],
            [
                'path' => '/business-example-page',
                'label' => 'עמוד דוגמה לעסק',
                'title' => 'עמוד דוגמה לעסק עם חנות, שירותים ומודעות | Sveevee',
                'description' => 'צפו בעמוד דוגמה לעסק ב-Sveevee עם מוצרים, שירותים, מודעות, דירוגים, כתובת, שעות פתיחה, רשתות חברתיות, צאט, WhatsApp ומפות.',
                'image' => '/assets/landing/example-business-banner-1440.v1.webp',
                'free' => 'דוגמה סטטית לעמוד עסק חינמי עם חנות, שירותים ומודעות פעילים.',
                'sections' => [
                    [
                        'title' => 'מה מופיע בעמוד העסק לדוגמה',
                        'items' => [
                            'פרופיל ציבורי מלא עם לוגו, באנר, תיאור, עיר, שכונה, כתובת, שעות פתיחה ופרטי קשר.',
                            'מודולי מוצרים, שירותים ומודעות פעילים באותו עמוד.',
                            'דירוגים, קישורים חברתיים, WhatsApp, מפות, צאט ושיתוף קישור או QR.',
                        ],
                    ],
                    [
                        'title' => 'למה זה שימושי לפני הרשמה',
                        'items' => [
                            'מבקרים יכולים לראות מראש איך עמוד מלא נראה בפועל.',
                            'עסקים וקהילות מבינים אילו שדות כדאי למלא כדי שהעמוד יהיה ברור וחזק יותר.',
                            'העמוד הציבורי מסביר את הערך של Sveevee גם בלי כניסה לחשבון.',
                        ],
                    ],
                ],
            ],
            [
                'path' => '/community-example-page',
                'label' => 'עמוד דוגמה לקהילה',
                'title' => 'עמוד דוגמה לקהילה עם אירועים ומודעות | Sveevee',
                'description' => 'צפו בעמוד דוגמה לקהילה ב-Sveevee עם אירועים, מודעות, דירוגים, כתובת, שעות פעילות, רשתות חברתיות, צאט, WhatsApp ומפות.',
                'image' => '/assets/landing/example-community-banner-1440.v1.webp',
                'free' => 'דוגמה סטטית לעמוד קהילה חינמי עם אירועים ומודעות פעילים.',
                'sections' => [
                    [
                        'title' => 'מה מופיע בעמוד הקהילה לדוגמה',
                        'items' => [
                            'פרופיל ציבורי מלא עם לוגו, באנר, תיאור, עיר, שכונה, כתובת, שעות פעילות ופרטי קשר.',
                            'אירועים עם תמונה, תאריך, שעה, תיאור וכתובת שנפתחת במפה.',
                            'מודעות קהילה, דירוגים, קישורים חברתיים, WhatsApp, צאט ושיתוף קישור או QR.',
                        ],
                    ],
                    [
                        'title' => 'למה זה שימושי לפני הרשמה',
                        'items' => [
                            'מבקרים יכולים לראות מראש איך עמוד קהילה מלא נראה בפועל.',
                            'קהילות מבינות אילו שדות כדאי למלא כדי שהעמוד יהיה ברור וחזק יותר.',
                            'העמוד הציבורי מסביר את הערך של Sveevee גם בלי כניסה לחשבון.',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function marketingHtml(string $indexHtml, array $page): string
    {
        $meta = $this->marketingMeta($page);

        return $this->decorateIndex($indexHtml, $meta, $this->marketingBody($page, $meta), [
            $this->marketingSchema($meta),
            $this->breadcrumbSchema($meta['breadcrumbs']),
        ]);
    }

    private function marketingMeta(array $page): array
    {
        [$imageWidth, $imageHeight] = $this->staticImageDimensions($page['image'] ?? '');

        return [
            'locale' => 'he',
            'dir' => 'rtl',
            'type' => 'website',
            'title' => $page['title'],
            'description' => $this->truncate($page['description']),
            'canonical' => $this->absoluteUrl($page['path']),
            'image' => $this->absoluteUrl($page['image']),
            'image_alt' => $page['label'],
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
            'alternates' => [],
            'label' => $page['label'],
            'path' => $page['path'],
            'breadcrumbs' => [
                [
                    'label' => 'Sveevee',
                    'path' => '/',
                ],
                [
                    'label' => $page['label'],
                    'path' => $page['path'],
                ],
            ],
        ];
    }

    private function marketingBody(array $page, array $meta): string
    {
        $sections = collect($page['sections'] ?? [])
            ->map(function (array $section): string {
                $items = collect($section['items'] ?? [])
                    ->map(fn (string $item): string => '<li>'.$this->escape($item).'</li>')
                    ->implode('');

                return $this->section($section['title'] ?? '', '<ul>'.$items.'</ul>');
            })
            ->implode('');

        return '<main class="sveevee-prerender"><article class="sveevee-prerender__card">'
            .$this->brand()
            .$this->breadcrumbHtml($meta['breadcrumbs'])
            .'<img class="sveevee-prerender__image" src="'.$this->escapeAttribute($meta['image']).'" alt="'.$this->escapeAttribute($meta['image_alt'] ?? $meta['label']).'" />'
            .'<h1>'.$this->escape($meta['label']).'</h1>'
            .'<p class="sveevee-prerender__lead">'.$this->escape($meta['description']).'</p>'
            .'<p><strong>'.$this->escape($page['free']).'</strong></p>'
            .$sections
            .'</article></main>';
    }

    private function catalogHubMeta(array $hub, string $locale): array
    {
        $label = $this->translated($hub['labels'] ?? [], $locale);
        $description = $this->translated($hub['descriptions'] ?? [], $locale);
        $path = $hub['path'] ?? '/catalog/'.$hub['slug'];

        return [
            'locale' => $locale,
            'dir' => $this->direction($locale),
            'type' => 'website',
            'title' => $label.' | Sveevee',
            'description' => $this->truncate($description),
            'canonical' => $this->absoluteUrl($path),
            'image' => $this->absoluteUrl('/favicon.png'),
            'image_alt' => 'Sveevee',
            'image_width' => null,
            'image_height' => null,
            'alternates' => [],
            'label' => $label,
            'path' => $path,
            'breadcrumbs' => [
                [
                    'label' => 'Sveevee',
                    'path' => '/',
                ],
                [
                    'label' => $label,
                    'path' => $path,
                ],
            ],
        ];
    }

    private function businessMeta(Page $page, string $locale): array
    {
        $address = $this->pageAddress($page);
        $topic = CatalogTopics::findByKey($page->category_key);
        $category = $this->topicLabel($topic, $locale) ?: $this->copy($locale, 'businessFallback');
        $city = $address['city'] ?: $this->copy($locale, 'location');
        $neighborhood = $address['neighborhood'] ? ' '.$address['neighborhood'] : '';
        $description = $this->cleanText($page->public_description) ?: $this->template($locale, 'businessDescription', [
            'name' => $page->name,
            'category' => $category,
            'city' => $city,
            'neighborhood' => $neighborhood,
        ]);
        $path = $this->businessPath($page, $locale);

        return [
            'locale' => $locale,
            'dir' => $this->direction($locale),
            'type' => 'website',
            'title' => $this->template($locale, 'businessTitle', [
                'name' => $page->name,
                'city' => $city,
            ]),
            'description' => $this->truncate($description),
            'canonical' => $this->absoluteUrl($path),
            'image' => $this->absoluteUrl($page->banner_url ?: $page->logo_url ?: '/favicon.png'),
            'image_alt' => $page->name,
            'image_width' => null,
            'image_height' => null,
            'alternates' => $this->alternates(fn (string $item): string => $this->businessPath($page, $item)),
            'category' => $category,
            'location' => $this->locationText($address),
            'address' => $address,
            'topic' => $topic,
            'breadcrumbs' => $this->breadcrumbs($topic, $address, $page->name, $path, $locale),
        ];
    }

    private function productMeta(PageProduct $product, string $locale): array
    {
        $page = $product->page;
        $address = $this->pageAddress($page);
        $topic = CatalogTopics::findByKey($product->category_key);
        $category = $this->topicLabel($topic, $locale);
        $city = $address['city'] ?: $this->copy($locale, 'location');
        $description = $this->template($locale, 'productDescription', [
            'name' => $product->name,
            'city' => $city,
            'price' => $this->priceLabel($product),
        ]);
        $description = trim($description.' '.$this->cleanText($product->description));
        $path = $this->productPath($product, $locale);

        return [
            'locale' => $locale,
            'dir' => $this->direction($locale),
            'type' => 'product',
            'title' => $this->template($locale, 'productTitle', [
                'name' => $product->name,
                'city' => $city,
            ]),
            'description' => $this->truncate($description),
            'canonical' => $this->absoluteUrl($path),
            'image' => $this->absoluteUrl($product->image_url ?: '/favicon.png'),
            'image_alt' => $product->name,
            'image_width' => null,
            'image_height' => null,
            'alternates' => $this->alternates(fn (string $item): string => $this->productPath($product, $item)),
            'category' => $category,
            'location' => $this->locationText($address),
            'address' => $address,
            'topic' => $topic,
            'breadcrumbs' => $this->breadcrumbs($topic, $address, $product->name, $path, $locale),
        ];
    }

    private function decorateIndex(string $indexHtml, array $meta, string $bodyHtml, array $jsonLd): string
    {
        $html = preg_replace('/<html\b[^>]*>/i', '<html lang="'.$this->escapeAttribute($meta['locale']).'" dir="'.$this->escapeAttribute($meta['dir']).'">', $indexHtml, 1);
        $html = preg_replace('/<title>.*?<\/title>/is', '<title>'.$this->escape($meta['title']).'</title>', $html, 1);
        $html = $this->setMeta($html, 'name', 'description', $meta['description']);
        $html = $this->setMeta($html, 'name', 'robots', 'index,follow');
        $html = $this->setMeta($html, 'property', 'og:title', $meta['title']);
        $html = $this->setMeta($html, 'property', 'og:description', $meta['description']);
        $html = $this->setMeta($html, 'property', 'og:type', $meta['type']);
        $html = $this->setMeta($html, 'property', 'og:url', $meta['canonical']);
        $html = $this->setMeta($html, 'property', 'og:image', $meta['image']);
        if (filled($meta['image_alt'] ?? null)) {
            $html = $this->setMeta($html, 'property', 'og:image:alt', $meta['image_alt']);
        }
        if (filled($meta['image_width'] ?? null)) {
            $html = $this->setMeta($html, 'property', 'og:image:width', (string) $meta['image_width']);
        }
        if (filled($meta['image_height'] ?? null)) {
            $html = $this->setMeta($html, 'property', 'og:image:height', (string) $meta['image_height']);
        }
        $html = $this->setMeta($html, 'name', 'twitter:card', 'summary_large_image');
        $html = $this->setMeta($html, 'name', 'twitter:title', $meta['title']);
        $html = $this->setMeta($html, 'name', 'twitter:description', $meta['description']);
        $html = $this->setMeta($html, 'name', 'twitter:image', $meta['image']);
        if (filled($meta['image_alt'] ?? null)) {
            $html = $this->setMeta($html, 'name', 'twitter:image:alt', $meta['image_alt']);
        }
        $html = $this->setCanonical($html, $meta['canonical']);
        $html = $this->removePrerenderHead($html);
        $html = $this->removeNoscriptFallback($html);
        $html = str_replace('</head>', $this->prerenderHead($meta, $jsonLd)."\n  </head>", $html);

        return preg_replace('/<div id="app"([^>]*)>.*?<\/div>/is', '<div id="app"$1>'.$bodyHtml.'</div>', $html, 1);
    }

    private function businessBody(Page $page, string $locale, array $meta): string
    {
        $copy = self::COPY[$locale];
        $contactRows = [
            $page->phone ? [$copy['phone'], $page->phone] : null,
            $page->contact_email ? [$copy['email'], $page->contact_email] : null,
        ];
        $detailRows = [
            [$copy['category'], $meta['category']],
            $meta['location'] ? [$copy['location'], $meta['location']] : null,
            $this->ratingText($page, $locale) ? [$copy['rating'], $this->ratingText($page, $locale)] : null,
        ];
        $hours = $this->openingHoursText($page, $locale);
        $prices = data_get($page->setup, 'features.price_list', false) ? $page->prices->take(12)->map(fn ($price): string => sprintf(
            '<li><strong>%s</strong><span>%s</span></li>',
            $this->escape($price->name),
            $this->escape('₪'.number_format((float) $price->price, 2))
        ))->implode('') : '';
        $products = data_get($page->setup, 'features.store', false) ? $page->products->take(8)->map(fn (PageProduct $product): string => sprintf(
            '<li><a href="%s">%s</a> <span>%s</span></li>',
            $this->escapeAttribute($this->productPath($product, $locale)),
            $this->escape($product->name),
            $this->escape($this->priceLabel($product))
        ))->implode('') : '';
        $services = data_get($page->setup, 'features.services', false) ? $page->services->take(8)->map(fn ($service): string => sprintf(
            '<li><strong>%s</strong><span>%s</span></li>',
            $this->escape($service->name),
            $this->escape($this->truncate($service->description, 110))
        ))->implode('') : '';

        return '<main class="sveevee-prerender"><article class="sveevee-prerender__card">'
            .$this->brand()
            .$this->breadcrumbHtml($meta['breadcrumbs'])
            .($page->banner_url ? '<img class="sveevee-prerender__image" src="'.$this->escapeAttribute($this->absoluteUrl($page->banner_url)).'" alt="'.$this->escapeAttribute($page->name).'" />' : '')
            .'<h1>'.$this->escape($page->name).'</h1>'
            .'<p class="sveevee-prerender__lead">'.$this->escape($meta['description']).'</p>'
            .$this->definitionList($detailRows)
            .$this->section($copy['contact'], $this->definitionList($contactRows))
            .($hours ? $this->section($copy['openingHours'], '<p>'.$this->escape($hours).'</p>') : '')
            .($prices ? $this->section($copy['priceList'], '<ul>'.$prices.'</ul>') : '')
            .($products ? $this->section($copy['products'], '<ul>'.$products.'</ul>') : '')
            .($services ? $this->section($copy['services'], '<ul>'.$services.'</ul>') : '')
            .'</article></main>';
    }

    private function productBody(PageProduct $product, string $locale, array $meta): string
    {
        $copy = self::COPY[$locale];
        $page = $product->page;
        $rows = [
            [$copy['price'], $this->priceLabel($product)],
            $product->hasActiveOffer() ? [$copy['normalPrice'], '₪'.number_format((float) $product->price, 2)] : null,
            $product->brand ? [$copy['brand'], $product->brand] : null,
            $product->model ? [$copy['model'], $product->model] : null,
            $meta['category'] ? [$copy['category'], $meta['category']] : null,
            $meta['location'] ? [$copy['location'], $meta['location']] : null,
            [$copy['availability'], $copy['available']],
            [$copy['seller'], $page->name],
        ];
        $sellerLink = '<p><a href="'.$this->escapeAttribute($this->businessPath($page, $locale)).'">'.$this->escape($page->name).'</a></p>';

        return '<main class="sveevee-prerender"><article class="sveevee-prerender__card">'
            .$this->brand()
            .$this->breadcrumbHtml($meta['breadcrumbs'])
            .($product->image_url ? '<img class="sveevee-prerender__image" src="'.$this->escapeAttribute($product->image_url).'" alt="'.$this->escapeAttribute($product->name).'" />' : '')
            .'<h1>'.$this->escape($product->name).'</h1>'
            .'<p class="sveevee-prerender__price">'.$this->escape($this->priceLabel($product)).'</p>'
            .$this->definitionList($rows)
            .$this->section($copy['description'], '<p>'.$this->escape($product->description).'</p>')
            .$this->section($copy['seller'], $sellerLink)
            .'</article></main>';
    }

    private function catalogHubBody(array $hub, string $locale, array $meta): string
    {
        $groups = collect(CatalogTopics::publicPayload($hub['scopes'] ?? [])['groups'] ?? []);
        $sections = $groups
            ->map(function (array $group) use ($locale): string {
                $topics = collect($group['topics'] ?? [])
                    ->map(fn (array $topic): string => sprintf(
                        '<li><a href="%s">%s</a></li>',
                        $this->escapeAttribute(CatalogTopics::catalogPath($topic)),
                        $this->escape($this->translated($topic['labels'] ?? [], $locale))
                    ))
                    ->implode('');

                if ($topics === '') {
                    return '';
                }

                return $this->section(
                    $this->translated($group['labels'] ?? [], $locale),
                    '<ul>'.$topics.'</ul>'
                );
            })
            ->filter()
            ->implode('');

        return '<main class="sveevee-prerender"><article class="sveevee-prerender__card">'
            .$this->brand()
            .$this->breadcrumbHtml($meta['breadcrumbs'])
            .'<h1>'.$this->escape($meta['label']).'</h1>'
            .'<p class="sveevee-prerender__lead">'.$this->escape($meta['description']).'</p>'
            .$sections
            .'</article></main>';
    }

    private function businessSchema(Page $page, string $locale, array $meta): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $page->name,
            'description' => $meta['description'],
            'url' => $meta['canonical'],
            'image' => $page->banner_url ? $this->absoluteUrl($page->banner_url) : null,
            'logo' => $page->logo_url ? $this->absoluteUrl($page->logo_url) : null,
            'telephone' => $page->phone,
            'email' => $page->contact_email,
            'address' => $this->addressSchema($meta['address']),
            'openingHoursSpecification' => $this->openingHoursSchema($page),
            'aggregateRating' => $this->ratingSchema($page),
        ];

        return $this->withoutEmpty($schema);
    }

    private function productSchema(PageProduct $product, string $locale, array $meta): array
    {
        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $meta['description'],
            'image' => $product->image_url ? $this->absoluteUrl($product->image_url) : null,
            'url' => $meta['canonical'],
            'category' => $meta['category'],
            'brand' => $product->brand ? [
                '@type' => 'Brand',
                'name' => $product->brand,
            ] : null,
            'model' => $product->model,
            'offers' => [
                '@type' => 'Offer',
                'price' => $product->currentPrice(),
                'priceCurrency' => 'ILS',
                'availability' => 'https://schema.org/InStock',
                'validFrom' => $product->hasActiveOffer() ? $product->offer_starts_at?->toISOString() : null,
                'priceValidUntil' => $product->hasActiveOffer() ? $product->offer_ends_at?->toISOString() : null,
                'url' => $meta['canonical'],
                'seller' => [
                    '@type' => 'Organization',
                    'name' => $product->page->name,
                    'url' => $this->absoluteUrl($this->businessPath($product->page, $locale)),
                ],
            ],
        ]);
    }

    private function catalogHubSchema(array $meta): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $meta['label'],
            'description' => $meta['description'],
            'url' => $meta['canonical'],
        ];
    }

    private function marketingSchema(array $meta): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $meta['label'],
            'description' => $meta['description'],
            'url' => $meta['canonical'],
            'image' => $meta['image'],
            'primaryImageOfPage' => $this->imageObject($meta),
        ];
    }

    private function imageObject(array $meta): array
    {
        return $this->withoutEmpty([
            '@type' => 'ImageObject',
            'url' => $meta['image'] ?? null,
            'caption' => $meta['image_alt'] ?? null,
            'width' => $meta['image_width'] ?? null,
            'height' => $meta['image_height'] ?? null,
        ]);
    }

    private function staticImageDimensions(string $path): array
    {
        if (str_contains($path, 'example-') && str_contains($path, '-banner-')) {
            return [1440, 640];
        }

        if (str_contains($path, 'hero-main-') || str_contains($path, 'promo-')) {
            return [1360, 765];
        }

        if (str_contains($path, 'sveevee-logo-640')) {
            return [640, 125];
        }

        return [null, null];
    }

    private function catalogHubItemListSchema(array $hub, string $locale): array
    {
        $topics = collect(CatalogTopics::publicPayload($hub['scopes'] ?? [])['groups'] ?? [])
            ->flatMap(fn (array $group): array => $group['topics'] ?? [])
            ->take(40)
            ->values();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $topics
                ->map(fn (array $topic, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $this->translated($topic['labels'] ?? [], $locale),
                    'url' => $this->absoluteUrl(CatalogTopics::catalogPath($topic)),
                ])
                ->all(),
        ];
    }

    private function breadcrumbSchema(array $breadcrumbs): ?array
    {
        if (count($breadcrumbs) < 2) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbs)->map(fn (array $item, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['label'],
                'item' => $this->absoluteUrl($item['path']),
            ])->values()->all(),
        ];
    }

    private function breadcrumbs(?array $topic, array $address, string $currentLabel, string $currentPath, string $locale): array
    {
        $items = [];

        if ($topic && filled($address['city'] ?? null)) {
            $items[] = [
                'label' => $address['city'],
                'path' => CatalogTopics::catalogPath($topic, $address['city']),
            ];
        }

        if ($topic && filled($address['city'] ?? null) && filled($address['neighborhood'] ?? null)) {
            $items[] = [
                'label' => $address['neighborhood'],
                'path' => CatalogTopics::catalogPath($topic, $address['city'], $address['neighborhood']),
            ];
        }

        if ($topic) {
            $items[] = [
                'label' => $this->topicLabel($topic, $locale) ?: $topic['slug'],
                'path' => CatalogTopics::catalogPath($topic),
            ];
        }

        $items[] = [
            'label' => $currentLabel,
            'path' => $currentPath,
        ];

        return $items;
    }

    private function setMeta(string $html, string $attribute, string $key, string $content): string
    {
        $tag = '<meta '.$attribute.'="'.$this->escapeAttribute($key).'" content="'.$this->escapeAttribute($content).'" />';
        $pattern = '/<meta\s+'.$attribute.'=["\']'.preg_quote($key, '/').'["\'][^>]*>/i';

        if (preg_match($pattern, $html)) {
            return preg_replace($pattern, $tag, $html, 1);
        }

        return str_replace('</head>', '    '.$tag."\n  </head>", $html);
    }

    private function setCanonical(string $html, string $url): string
    {
        $tag = '<link rel="canonical" href="'.$this->escapeAttribute($url).'" />';

        if (preg_match('/<link\s+rel=["\']canonical["\'][^>]*>/i', $html)) {
            return preg_replace('/<link\s+rel=["\']canonical["\'][^>]*>/i', $tag, $html, 1);
        }

        return str_replace('</head>', '    '.$tag."\n  </head>", $html);
    }

    private function prerenderHead(array $meta, array $jsonLd): string
    {
        $links = collect($meta['alternates'])->map(fn (string $url, string $locale): string => sprintf(
            '    <link rel="alternate" hreflang="%s" href="%s" data-sveevee-prerender />',
            $this->escapeAttribute($locale),
            $this->escapeAttribute($this->absoluteUrl($url))
        ))->implode("\n");
        $schema = collect($jsonLd)->filter()->values()->all();
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "\n".$this->prerenderStyle()."\n".$links."\n".'    <script type="application/ld+json" data-sveevee-prerender>'.$json.'</script>';
    }

    private function removePrerenderHead(string $html): string
    {
        $html = preg_replace('/\s*<link\b[^>]*data-sveevee-prerender[^>]*>/i', '', $html);
        $html = preg_replace('/\s*<script\b[^>]*data-sveevee-prerender[^>]*>.*?<\/script>/is', '', $html);

        return preg_replace('/\s*<style\b[^>]*data-sveevee-prerender[^>]*>.*?<\/style>/is', '', $html);
    }

    private function removeNoscriptFallback(string $html): string
    {
        return preg_replace('/\s*<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);
    }

    private function prerenderStyle(): string
    {
        return <<<'HTML'
    <style data-sveevee-prerender>
      .sveevee-prerender{min-height:100vh;padding:34px 18px;background:#fff8fb;color:#151f3b;font-family:Arial,sans-serif}
      .sveevee-prerender__card{max-width:940px;margin:0 auto;padding:28px;border:1px solid rgba(17,34,45,.1);border-radius:8px;background:#fff}
      .sveevee-prerender__brand{margin:0 0 12px;color:#7b3ff2;font-weight:800;text-transform:lowercase}
      .sveevee-prerender h1{margin:0 0 14px;font-size:42px;line-height:1.08}
      .sveevee-prerender h2{margin:24px 0 10px;font-size:24px}
      .sveevee-prerender p,.sveevee-prerender li,.sveevee-prerender dd,.sveevee-prerender dt{font-size:16px;line-height:1.65}
      .sveevee-prerender__lead{color:rgba(21,31,59,.76)}
      .sveevee-prerender__breadcrumbs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
      .sveevee-prerender a{color:#6633d8;font-weight:700}
      .sveevee-prerender dl{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:8px 16px}
      .sveevee-prerender dt{font-weight:800}
      .sveevee-prerender dd{margin:0}
      .sveevee-prerender ul{display:grid;gap:8px;padding-inline-start:22px}
      .sveevee-prerender__image{width:100%;max-height:420px;object-fit:cover;border-radius:8px}
      .sveevee-prerender__price{font-size:28px;font-weight:850}
    </style>
HTML;
    }

    private function definitionList(array $rows): string
    {
        $items = collect($rows)
            ->filter(fn ($row): bool => is_array($row) && filled($row[1] ?? null))
            ->map(fn (array $row): string => '<dt>'.$this->escape($row[0]).'</dt><dd>'.$this->escape($row[1]).'</dd>')
            ->implode('');

        return $items ? '<dl>'.$items.'</dl>' : '';
    }

    private function section(string $title, string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        return '<section><h2>'.$this->escape($title).'</h2>'.$body.'</section>';
    }

    private function breadcrumbHtml(array $breadcrumbs): string
    {
        if (count($breadcrumbs) < 2) {
            return '';
        }

        $links = collect($breadcrumbs)->map(fn (array $item): string => '<a href="'.$this->escapeAttribute($item['path']).'">'.$this->escape($item['label']).'</a>')->implode(' / ');

        return '<nav class="sveevee-prerender__breadcrumbs" aria-label="Breadcrumb">'.$links.'</nav>';
    }

    private function brand(): string
    {
        return '<p class="sveevee-prerender__brand">sveevee</p>';
    }

    private function openingHoursText(Page $page, string $locale): string
    {
        $hours = collect($page->setup['opening_hours'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && ($item['is_open'] ?? false) && filled($item['opens_at'] ?? null) && filled($item['closes_at'] ?? null))
            ->map(fn (array $item): string => trim(($item['weekday'] ?? '').' '.$item['opens_at'].'-'.$item['closes_at']))
            ->implode(', ');

        return $hours;
    }

    private function openingHoursSchema(Page $page): array
    {
        return collect($page->setup['opening_hours'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && ($item['is_open'] ?? false) && filled($item['opens_at'] ?? null) && filled($item['closes_at'] ?? null) && isset(self::SCHEMA_WEEKDAYS[$item['weekday'] ?? '']))
            ->map(fn (array $item): array => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/'.self::SCHEMA_WEEKDAYS[$item['weekday']],
                'opens' => $item['opens_at'],
                'closes' => $item['closes_at'],
            ])
            ->values()
            ->all();
    }

    private function addressSchema(array $address): ?array
    {
        if (! filled($address['city'] ?? null) && ! filled($address['street'] ?? null)) {
            return null;
        }

        return $this->withoutEmpty([
            '@type' => 'PostalAddress',
            'streetAddress' => trim(($address['street'] ?? '').' '.($address['number'] ?? '')) ?: null,
            'addressLocality' => $address['city'] ?? null,
            'addressRegion' => $address['neighborhood'] ?? null,
            'addressCountry' => 'IL',
        ]);
    }

    private function ratingSchema(Page $page): ?array
    {
        $count = (int) ($page->ratings_count ?? 0);

        if ($count <= 0) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => round((float) $page->ratings_avg_rating, 1),
            'ratingCount' => $count,
        ];
    }

    private function ratingText(Page $page, string $locale): string
    {
        $count = (int) ($page->ratings_count ?? 0);

        if ($count <= 0) {
            return '';
        }

        return round((float) $page->ratings_avg_rating, 1).' / 5 ('.$count.')';
    }

    private function pageAddress(?Page $page): array
    {
        $address = is_array($page?->setup['address'] ?? null) ? $page->setup['address'] : [];

        return [
            'street' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'city' => $address['city'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
        ];
    }

    private function locationText(array $address): string
    {
        return collect([$address['city'] ?? null, $address['neighborhood'] ?? null, $address['street'] ?? null, $address['number'] ?? null])
            ->filter(fn ($value): bool => filled($value))
            ->implode(', ');
    }

    private function topicLabel(?array $topic, string $locale): string
    {
        if (! $topic) {
            return '';
        }

        return $this->translated($topic['labels'] ?? [], $locale);
    }

    private function translated(array $values, string $locale): string
    {
        return $values[$locale] ?? $values['he'] ?? $values['en'] ?? '';
    }

    private function priceLabel(PageProduct $product): string
    {
        return '₪'.number_format($product->currentPrice(), 2);
    }

    private function businessPath(Page $page, string $locale): string
    {
        return "/{$locale}/business/{$page->public_slug}";
    }

    private function productPath(PageProduct $product, string $locale): string
    {
        return "/{$locale}/product/{$product->public_slug}";
    }

    private function alternates(callable $pathFactory): array
    {
        return [
            ...collect(self::LOCALES)->mapWithKeys(fn (string $locale): array => [$locale => $pathFactory($locale)])->all(),
            'x-default' => $pathFactory('he'),
        ];
    }

    private function template(string $locale, string $key, array $values): string
    {
        return strtr($this->copy($locale, $key), collect($values)->mapWithKeys(fn ($value, string $name): array => ['{'.$name.'}' => (string) $value])->all());
    }

    private function copy(string $locale, string $key): string
    {
        return self::COPY[$locale][$key] ?? self::COPY['he'][$key] ?? '';
    }

    private function direction(string $locale): string
    {
        return in_array($locale, self::RTL_LOCALES, true) ? 'rtl' : 'ltr';
    }

    private function absoluteUrl(?string $path): string
    {
        if (! filled($path)) {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function cleanText(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function truncate(string $value, int $length = 155): string
    {
        $text = $this->cleanText($value);

        return mb_strlen($text) <= $length
            ? $text
            : rtrim(mb_substr($text, 0, $length - 3)).'...';
    }

    private function escape(?string $value): string
    {
        return e((string) $value);
    }

    private function escapeAttribute(?string $value): string
    {
        return e((string) $value, false);
    }

    private function withoutEmpty(array $value): array
    {
        return collect($value)
            ->reject(fn ($item): bool => $item === null || $item === [] || $item === '')
            ->all();
    }

    private function resolveDistPath(?string $distPath): string
    {
        $path = $distPath ?: base_path('../frontend/dist');

        if (! preg_match('/^(?:[A-Za-z]:[\/\\\\]|\/|\\\\)/', $path)) {
            $path = base_path($path);
        }

        $realPath = realpath($path);

        if (! $realPath || ! File::isDirectory($realPath)) {
            throw new RuntimeException("Frontend dist directory was not found at {$path}.");
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }

    private function writePage(string $dist, string $path, string $html): string
    {
        $relative = trim($path, '/').'/index.html';
        $target = $dist.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDir = dirname($target);

        File::ensureDirectoryExists($targetDir);
        $this->assertInsideDist($dist, $targetDir);
        File::put($target, $html);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function cleanPreviousFiles(string $dist): void
    {
        $manifestPath = $dist.DIRECTORY_SEPARATOR.self::MANIFEST;

        if (! File::exists($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

        foreach ($files as $file) {
            if (! is_string($file) || str_contains($file, '..')) {
                continue;
            }

            $target = $dist.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($file, '/'));

            if (File::exists($target)) {
                File::delete($target);
            }
        }
    }

    private function assertInsideDist(string $dist, string $targetDir): void
    {
        $base = rtrim((string) realpath($dist), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $target = rtrim((string) realpath($targetDir), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($target, $base)) {
            throw new RuntimeException('Refusing to write outside frontend dist.');
        }
    }
}
