<?php

return [
    'creator_login' => env('BUSINESS_IMPORT_CREATOR_LOGIN', 'spfksfmbvpt'),
    'max_batch_size' => (int) env('BUSINESS_IMPORT_MAX_BATCH_SIZE', 1000),
    'token_ttl_minutes' => (int) env('BUSINESS_IMPORT_TOKEN_TTL_MINUTES', 60),
    'requests_per_minute' => (int) env('BUSINESS_IMPORT_REQUESTS_PER_MINUTE', 120),
    'requests_per_hour' => (int) env('BUSINESS_IMPORT_REQUESTS_PER_HOUR', 7200),
];
