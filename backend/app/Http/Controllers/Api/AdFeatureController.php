<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\BusinessProFeature;
use App\Models\Page;
use App\Services\ApiResponseService;
use App\Services\BusinessProEntitlementService;
use Illuminate\Http\Request;

class AdFeatureController extends Controller
{
    public function __invoke(Request $request, BusinessProEntitlementService $entitlements)
    {
        $data = $request->validate([
            'page_id' => ['nullable', 'integer', 'min:1'],
            'ad_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $owner = $request->user();
        $page = null;
        $eligibleType = true;
        if (! empty($data['ad_id'])) {
            $ad = Ad::query()->with(['user', 'page'])->findOrFail($data['ad_id']);
            abort_unless($ad->user_id === $owner->id || $owner->hasRole('admin'), 404);
            $owner = $ad->user;
            $page = $ad->page;
            $eligibleType = $ad->type === Ad::TYPE_PRIVATE || ($ad->type === Ad::TYPE_BUSINESS && $page !== null);
        } elseif (! empty($data['page_id'])) {
            $page = Page::query()->where('user_id', $owner->id)->where('is_unclaimed', false)->findOrFail($data['page_id']);
        }
        $eligibleType = $eligibleType && ($page === null || $page->type === Page::TYPE_BUSINESS);
        $featureEnabled = config('business_pro.features.featured_ads.implemented', false)
            && BusinessProFeature::where('key', 'featured_ads')->where('enabled', true)->where('lifecycle', 'published')->exists();
        $available = $eligibleType && $owner && $entitlements->canFeatureAd($owner, $page);

        return ApiResponseService::success([
            'key' => 'featured_ads',
            'available' => (bool) $available,
            'locked_reason' => $available ? null : (! $eligibleType || ! $featureEnabled ? 'not_available' : 'subscription_required'),
            'required_plans' => $page ? ['business_pro'] : ['private_pro', 'business_pro'],
        ]);
    }
}
