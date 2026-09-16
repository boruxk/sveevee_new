<?php

return [
    'creator_login' => env('BUSINESS_IMPORT_CREATOR_LOGIN', 'spfksfmbvpt'),
    'max_batch_size' => (int) env('BUSINESS_IMPORT_MAX_BATCH_SIZE', 1000),
    // OSM remains a private preview until public ODbL-derived imports are authorized.
    'osm_public_import_enabled' => filter_var(env('BUSINESS_IMPORT_OSM_PUBLIC_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'token_ttl_minutes' => (int) env('BUSINESS_IMPORT_TOKEN_TTL_MINUTES', 60),
    'requests_per_minute' => (int) env('BUSINESS_IMPORT_REQUESTS_PER_MINUTE', 480),
    'requests_per_hour' => (int) env('BUSINESS_IMPORT_REQUESTS_PER_HOUR', 28800),
];
