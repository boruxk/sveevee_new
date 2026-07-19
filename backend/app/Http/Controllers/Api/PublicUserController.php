<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;

class PublicUserController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function show(User $user)
    {
        if ($user->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $user->load(['profile', 'pages.ads.user.profile', 'pages.ads.page']);
        $privateAds = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->where('user_id', $user->id)
            ->whereNull('page_id')
            ->latest()
            ->get()
            ->map(fn (Ad $ad) => $this->payloads->ad($ad))
            ->values();

        return ApiResponseService::success([
            ...$this->payloads->user($user),
            'private_ads' => $privateAds,
        ]);
    }
}
