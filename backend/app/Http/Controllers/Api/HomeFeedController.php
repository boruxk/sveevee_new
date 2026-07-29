<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class HomeFeedController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $profile = $request->user()->profile;

        $ads = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->inLocation($profile?->city, $profile?->neighborhood)
            ->latest()
            ->limit(60)
            ->get();

        return ApiResponseService::success($ads->map(fn (Ad $ad) => $this->payloads->ad($ad))->values());
    }
}
