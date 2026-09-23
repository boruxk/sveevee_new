<?php

return [
    // Published paid controls are visible to everyone. This switch still gates
    // real purchases; sandbox purchases always require an admin/test account.
    'rollout' => env('BUSINESS_PRO_ROLLOUT', 'private'),
    'environment' => env('CARDCOM_ENV', 'sandbox'),
    'billing_enabled' => (bool) env('BUSINESS_PRO_BILLING_ENABLED', false),
    'renewals_enabled' => (bool) env('BUSINESS_PRO_RENEWALS_ENABLED', false),
    'amount_minor' => 4900,
    'private_amount_minor' => 1900,
    'currency' => 'ILS',
    'interval' => 'monthly',
    'included_pages' => 1,
    // Register implemented features here AND in business_pro_features. A DB row
    // alone can never turn unfinished code into an available paid feature.
    'features' => [
        'featured_ads' => [
            'implemented' => true,
            'plans' => ['private_pro', 'business_pro'],
            'labels' => ['en' => 'Featured ads', 'he' => 'מודעות מובלטות', 'ru' => 'Выделенные объявления', 'fr' => 'Annonces à la une'],
            'descriptions' => [
                'en' => 'Highlight your ads and give them priority within relevant results.',
                'he' => 'הבליטו את המודעות שלכם והעניקו להן עדיפות בתוצאות הרלוונטיות.',
                'ru' => 'Выделяйте свои объявления и поднимайте их выше среди подходящих результатов.',
                'fr' => 'Mettez vos annonces en valeur et donnez-leur la priorité parmi les résultats pertinents.',
            ],
            'sort_order' => 10,
        ],
    ],
    'cardcom' => [
        'terminal_number' => (int) env('CARDCOM_TERMINAL_NUMBER', 1000),
        'api_name' => env('CARDCOM_API_NAME'),
        'api_password' => env('CARDCOM_API_PASSWORD'),
        'production_enabled' => (bool) env('CARDCOM_PRODUCTION_ENABLED', false),
        'webhook_url' => env('CARDCOM_WEBHOOK_URL'),
        'frontend_url' => env('BUSINESS_PRO_FRONTEND_URL', env('FRONTEND_URL', 'http://localhost:5173')),
        'allow_local_callbacks' => (bool) env('CARDCOM_ALLOW_LOCAL_CALLBACKS', false),
        'auto_recurring_terminal' => (bool) env('CARDCOM_AUTO_RECURRING_TERMINAL', false),
    ],
];
