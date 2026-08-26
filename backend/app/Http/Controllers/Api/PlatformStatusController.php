<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\SystemSettingsService;

class PlatformStatusController extends Controller
{
    public function __invoke(SystemSettingsService $settings)
    {
        return ApiResponseService::success([
            'maintenance' => $settings->maintenanceStatus(),
        ]);
    }
}
