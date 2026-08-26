<?php

namespace App\Http\Middleware;

use App\Services\ApiResponseService;
use App\Services\SystemSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformIsAvailable
{
    public function __construct(private readonly SystemSettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = $this->settings->maintenanceStatus();

        if (! $maintenance['enabled']) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        if ($user?->hasRole('admin')) {
            return $next($request);
        }

        return ApiResponseService::error(
            message: $maintenance['messages']['en'] ?? 'Sveevee is currently undergoing maintenance.',
            status: 503,
            data: [
                'reason' => 'maintenance',
                'maintenance' => $maintenance,
            ]
        )->header('Retry-After', '300');
    }
}
