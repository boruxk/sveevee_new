<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\PageEvent;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;

class PublicUserController extends Controller
{
    public function __construct(private readonly PayloadService $payloads) {}

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
        $today = today()->toDateString();
        $personalEvents = PageEvent::query()
            ->with(['user.profile', 'user.pages'])
            ->where('user_id', $user->id)
            ->whereNull('page_id')
            ->orderByRaw('CASE WHEN event_date >= ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw('CASE WHEN event_date >= ? THEN event_date END ASC', [$today])
            ->orderByRaw('CASE WHEN event_date < ? THEN event_date END DESC', [$today])
            ->orderBy('event_time')
            ->get()
            ->map(fn (PageEvent $event) => $this->payloads->event($event))
            ->values();

        return ApiResponseService::success([
            ...$this->payloads->user($user),
            'private_ads' => $privateAds,
            'personal_events' => $personalEvents,
        ]);
    }
}
