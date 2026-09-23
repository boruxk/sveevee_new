<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Services\ApiResponseService;
use App\Services\BusinessProEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBusinessProController extends Controller
{
    public function subscriptions(Request $request, BusinessProEntitlementService $entitlements)
    {
        $rows = BusinessProSubscription::query()->with(['user:id,name,email,role,banned_at,business_pro_tester', 'page:id,name,user_id,type,is_unclaimed'])
            ->latest('id')->paginate($this->perPage($request));
        $items = $rows->getCollection()->map(fn (BusinessProSubscription $entry): array => [
            ...$entitlements->subscriptionPayload($entry),
            'user' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name, 'email' => $entry->user->email] : null,
            'page' => $entry->page ? ['id' => $entry->page->id, 'name' => $entry->page->name] : null,
        ])->all();

        return ApiResponseService::success(['items' => $items, 'pagination' => $this->pagination($rows)]);
    }

    public function payments(Request $request, BusinessProEntitlementService $entitlements)
    {
        $rows = BusinessProPayment::query()->with('user:id,name,email')->latest('id')->paginate($this->perPage($request));
        $items = $rows->getCollection()->map(fn (BusinessProPayment $entry): array => [
            ...$entitlements->paymentPayload($entry),
            'user' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name, 'email' => $entry->user->email] : null,
        ])->all();

        return ApiResponseService::success(['items' => $items, 'pagination' => $this->pagination($rows)]);
    }

    public function features(BusinessProEntitlementService $entitlements)
    {
        return ApiResponseService::success(['items' => BusinessProFeature::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (BusinessProFeature $entry): array => $entitlements->featurePayload($entry))->all()]);
    }

    public function updateFeature(Request $request, BusinessProFeature $feature, BusinessProEntitlementService $entitlements)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'required', 'boolean'],
            'lifecycle' => ['sometimes', 'required', Rule::in(['draft', 'published'])],
        ]);
        $feature->fill($data)->save();

        return ApiResponseService::success($entitlements->featurePayload($feature));
    }

    public function offer(Request $request, BusinessProEntitlementService $entitlements)
    {
        $data = $request->validate(['plan_key' => ['sometimes', Rule::in(['private_pro', 'business_pro'])]]);

        return ApiResponseService::success($entitlements->offer($data['plan_key'] ?? 'business_pro'));
    }

    public function updateOffer(Request $request, BusinessProEntitlementService $entitlements)
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:100', 'max:1000000'],
            'plan_key' => ['sometimes', Rule::in(['private_pro', 'business_pro'])]]);

        return ApiResponseService::success($entitlements->updateOffer((int) $data['amount_minor'], $request->user(), $data['plan_key'] ?? 'business_pro'));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 25)));
    }

    private function pagination($rows): array
    {
        return ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()];
    }
}
