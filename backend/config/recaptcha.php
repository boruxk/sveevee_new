<?php

return [
    'enabled' => env('RECAPTCHA_ENABLED', env('APP_ENV') !== 'testing'),
    'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
];
