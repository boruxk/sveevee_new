<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountNotificationService;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __construct(private readonly AccountNotificationService $notifications) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return ApiResponseService::success([
            'is_online' => true,
            'last_seen_at' => $user->last_seen_at?->toISOString(),
            'notifications' => $this->notifications->summary($user),
        ]);
    }
}
