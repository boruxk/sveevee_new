<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProPayment;
use App\Models\Page;
use App\Services\ApiResponseService;
use App\Services\Billing\BusinessProBillingException;
use App\Services\Billing\BusinessProBillingService;
use App\Services\BusinessProEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessProBillingController extends Controller
{
    public function __construct(private BusinessProBillingService $billing, private BusinessProEntitlementService $entitlements) {}

    public function checkout(Request $request): JsonResponse
    {
        $this->entitlements->assertVisible($request->user());
        $input = $request->validate([
            'page_id' => ['required', 'integer', 'min:1'],
            'consent' => ['required', 'accepted'],
            'amount_minor' => ['required', 'integer', 'min:100'],
            'currency' => ['required', 'in:ILS'],
            'locale' => ['sometimes', 'in:he,en,ru,fr'],
        ]);
        $page = Page::query()->findOrFail($input['page_id']);

        return $this->run(function () use ($request, $input, $page) {
            $payment = $this->billing->checkout($request->user(), $page, $input['amount_minor'], $input['currency'], $input['locale'] ?? 'he');

            return ['payment' => $this->entitlements->paymentPayload($payment), 'checkout_url' => $payment->checkout_url];
        });
    }

    public function verify(Request $request, BusinessProPayment $payment): JsonResponse
    {
        $this->entitlements->assertVisible($request->user());
        abort_unless((int) $payment->user_id === (int) $request->user()->id, 404);

        return $this->run(function () use ($request, $payment) {
            $payment = $this->billing->verify($payment);

            return ['payment' => $this->entitlements->paymentPayload($payment), 'overview' => $this->entitlements->overview($request->user())];
        });
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->entitlements->assertVisible($request->user());
        $this->billing->cancel($request->user());

        return ApiResponseService::success($this->entitlements->overview($request->user()));
    }

    public function webhook(Request $request): JsonResponse
    {
        // Cardcom callbacks can be GET or POST. All other supplied fields are hints,
        // never a signature or proof that a payment succeeded.
        $input = ['low_profile_id' => $request->input('LowProfileId', $request->input('lowprofileid'))];
        $validated = validator($input, ['low_profile_id' => ['required', 'uuid']])->validate();

        return $this->run(function () use ($validated) {
            $this->billing->webhook($validated['low_profile_id']);

            return ['received' => true]; // Do not expose account/payment details publicly.
        });
    }

    private function run(callable $callback): JsonResponse
    {
        try {
            return ApiResponseService::success($callback());
        } catch (BusinessProBillingException $error) {
            return ApiResponseService::error('Business Pro payment could not be completed.', ['reason' => $error->reason], $error->httpStatus);
        }
    }
}
