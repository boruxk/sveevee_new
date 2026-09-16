<?php

return [
    // Read the current build for every request, so imported pages never need a full export.
    'frontend_dist' => env('SEO_FRONTEND_DIST', base_path('../frontend/dist')),
];
