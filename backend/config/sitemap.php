<?php

return [
    'directory' => storage_path('app/sitemaps'),
    'max_urls' => 50000,
    'max_bytes' => 50 * 1024 * 1024,
    'batch_size' => 200,
    'retain_days' => 7,
];
