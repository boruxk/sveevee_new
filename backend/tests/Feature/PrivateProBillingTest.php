<?php

namespace Tests\Feature;

use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\Page;
use App\Models\User;
use App\Services\Billing\BusinessProBillingService;
use App\Services\Billing\CardcomClient;
use App\Services\BusinessProEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateProBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config()->set([
            'business_pro.rollout' => 'private', 'business_pro.environment' => 'sandbox',
            'business_pro.billing_enabled' => true, 'business_pro.renewals_enabled' => true,
            'business_pro.amount_minor' => 4900, 'business_pro.private_amount_minor' => 1900,
            'business_pro.cardcom.terminal_number' => 1000, 'business_pro.cardcom.api_name' => 'test-api-name',
            'business_pro.cardcom.frontend_url' => 'https://example.com',
            'business_pro.cardcom.webhook_url' => 'https://example.com/api/v1/billing/cardcom/webhook',
        ]);
    }

    public function test_both_offers_are_visible_without_business_page_but_sandbox_purchases_stay_private(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/business-pro')->assertOk()
            ->assertJsonPath('data.offers.0.plan_key', 'private_pro')
            ->assertJsonPath('data.offers.0.amount_minor', 1900)->assertJsonPath('data.offers.0.included_pages', 0)
            ->assertJsonPath('data.offers.0.can_checkout', false)
            ->assertJsonPath('data.offers.0.features.0.key', 'featured_ads')
            ->assertJsonPath('data.offers.0.features.0.available', false)
            ->assertJsonPath('data.offers.1.amount_minor', 4900);
        $this->postJson('/api/v1/business-pro/checkout', $this->form())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_private_checkout_without_page_only_unlocks_after_verified_matching_receipt(): void
    {
        $user = $this->tester();
        Sanctum::actingAs($user);
        $this->fakeCheckout();
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.offers.0.can_checkout', true)
            ->assertJsonPath('data.offers.1.can_checkout', false);
        $response = $this->postJson('/api/v1/business-pro/checkout', $this->form())->assertOk()
            ->assertJsonPath('data.payment.plan_key', 'private_pro')->assertJsonPath('data.payment.page_id', null);
        $this->postJson('/api/v1/business-pro/checkout', $this->form())->assertOk()
            ->assertJsonPath('data.payment.public_id', $response->json('data.payment.public_id'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/LowProfile/Create')
            && (float) $request['Amount'] === 19.0 && str_contains($request['ProductName'], 'Private Pro'));
        $payment = BusinessProPayment::firstOrFail();
        $this->assertFalse($this->entitlements()->hasAccess($user));
        $this->complete($payment);
        $this->assertTrue($this->entitlements()->hasAccess($user));
        $this->assertTrue($this->entitlements()->canFeatureAd($user));
        $this->assertSame(0, $payment->subscription->included_pages);
        $page = $this->page($user);
        $this->assertFalse($this->entitlements()->hasAccess($user, $page));
        $this->assertFalse($this->entitlements()->canFeatureAd($user, $page));
    }

    public function test_admin_can_price_each_plan_independently_without_repricing_current_contracts(): void
    {
        [$user, $subscription] = $this->active();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/v1/admin/business-pro/offer', ['plan_key' => 'private_pro', 'amount_minor' => 2900])
            ->assertOk()->assertJsonPath('data.plan_key', 'private_pro')->assertJsonPath('data.amount_minor', 2900);
        $this->getJson('/api/v1/admin/business-pro/offer?plan_key=private_pro')->assertOk()->assertJsonPath('data.amount_minor', 2900);
        $this->getJson('/api/v1/admin/business-pro/offer')->assertOk()->assertJsonPath('data.amount_minor', 4900);
        $this->assertSame(1900, $subscription->fresh()->amount_minor);
        $this->getJson('/api/v1/admin/business-pro/offer?plan_key=other')->assertUnprocessable();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/admin/business-pro/offer', ['plan_key' => 'private_pro', 'amount_minor' => 100])->assertForbidden();
    }

    public function test_private_plan_rejects_a_page_and_unknown_or_underpriced_tiers(): void
    {
        $user = $this->tester();
        Sanctum::actingAs($user);
        foreach ([['page_id' => $this->page($user)->id], ['plan_key' => 'other'], ['plan_key' => 'business_pro']] as $extra) {
            $this->postJson('/api/v1/business-pro/checkout', [...$this->form(), ...$extra])->assertUnprocessable();
        }
        $this->postJson('/api/v1/business-pro/checkout', [...$this->form(), 'amount_minor' => 100])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_pending_checkout_can_only_resume_its_original_plan_and_price(): void
    {
        $user = $this->tester();
        $this->page($user);
        $this->fakeCheckout();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/business-pro/checkout', $this->form())->assertOk();
        $this->getJson('/api/v1/business-pro')->assertOk()
            ->assertJsonPath('data.pending_plan_key', 'private_pro')
            ->assertJsonPath('data.pending_payment.plan_key', 'private_pro')
            ->assertJsonMissingPath('data.pending_payment.checkout_url')
            ->assertJsonPath('data.offers.0.can_checkout', true)->assertJsonPath('data.offers.0.can_resume', true)
            ->assertJsonPath('data.offers.1.can_checkout', false);
        config()->set('business_pro.private_amount_minor', 2900);
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.offers.0.can_checkout', false);
        $this->assertDatabaseCount('business_pro_payments', 1);
        $this->assertSame('pending', BusinessProPayment::firstOrFail()->status);
    }

    public function test_paid_private_contract_cannot_be_overwritten_by_business_or_another_private_checkout(): void
    {
        [$user] = $this->active();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/business-pro/checkout', $this->form())->assertStatus(409);
        $this->postJson('/api/v1/business-pro/checkout', [...$this->form(), 'plan_key' => 'business_pro',
            'page_id' => $this->page($user)->id, 'amount_minor' => 4900])->assertStatus(409);
        $this->postJson('/api/v1/business-pro/cancel')->assertOk();
        $this->assertTrue($this->entitlements()->hasAccess($user));
        $this->postJson('/api/v1/business-pro/checkout', $this->form())->assertStatus(409);
    }

    public function test_private_renewal_uses_frozen_price_and_does_not_require_a_business_page(): void
    {
        [$user, $subscription] = $this->active();
        $subscription->forceFill(['current_period_end' => now()->subMinute(), 'next_charge_at' => now()->subMinute()])->save();
        config()->set('business_pro.private_amount_minor', 3900);
        Http::fake([CardcomClient::BASE_URL.'/Transactions/Transaction' => Http::response($this->transaction(222))]);
        $renewal = app(BusinessProBillingService::class)->renew($subscription);
        $this->assertSame('paid', $renewal->status);
        $this->assertSame('private_pro', $renewal->plan_key);
        $this->assertNull($renewal->page_id);
        $this->assertSame(1900, $renewal->amount_minor);
        $this->assertTrue($this->entitlements()->canFeatureAd($user));
    }

    public function test_checkout_offers_allow_first_real_contract_but_never_a_merchant_mismatch(): void
    {
        [$user, $subscription] = $this->active();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.offers.0.can_checkout', false);
        config()->set(['business_pro.environment' => 'production', 'business_pro.cardcom.terminal_number' => 1234,
            'business_pro.cardcom.production_enabled' => true]);
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.has_access', false)
            ->assertJsonPath('data.offers.0.can_checkout', true);
        // Even an expired real contract cannot silently move its merchant identity.
        $subscription->forceFill(['environment' => 'production', 'terminal_number' => 4321,
            'current_period_end' => now()->subSecond()])->save();
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.offers.0.can_checkout', false);
        config()->set(['business_pro.environment' => 'sandbox', 'business_pro.cardcom.terminal_number' => 1000]);
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.offers.0.can_checkout', false);
    }

    public function test_private_feature_access_requires_matching_plan_receipt_enabled_feature_and_unexpired_payment(): void
    {
        [$user, $subscription] = $this->active();
        $payment = $subscription->payments()->firstOrFail();
        $payment->forceFill(['plan_key' => 'business_pro'])->save();
        $this->assertFalse($this->entitlements()->canFeatureAd($user));
        $payment->forceFill(['plan_key' => 'private_pro'])->save();
        BusinessProFeature::where('key', 'featured_ads')->update(['enabled' => false]);
        $this->assertFalse($this->entitlements()->canFeatureAd($user));
        BusinessProFeature::where('key', 'featured_ads')->update(['enabled' => true]);
        $subscription->forceFill(['current_period_end' => now()->subSecond()])->save();
        $this->assertFalse($this->entitlements()->canFeatureAd($user));
    }

    private function tester(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['business_pro_tester' => true])->save();

        return $user;
    }

    private function page(User $user): Page
    {
        return Page::create(['user_id' => $user->id, 'type' => 'business', 'name' => 'Private Pro test business', 'is_unclaimed' => false]);
    }

    private function form(): array
    {
        return ['plan_key' => 'private_pro', 'amount_minor' => 1900, 'currency' => 'ILS', 'consent' => true, 'locale' => 'en'];
    }

    private function fakeCheckout(): void
    {
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/Create' => Http::response([
            'ResponseCode' => 0, 'LowProfileId' => (string) Str::uuid(), 'Url' => 'https://secure.cardcom.solutions/test',
        ])]);
    }

    private function active(): array
    {
        $user = $this->tester();
        $this->fakeCheckout();
        $payment = app(BusinessProBillingService::class)->checkout($user, null, 1900, 'ILS', 'en', 'private_pro');
        $this->complete($payment);

        return [$user, $payment->subscription->fresh()];
    }

    private function complete(BusinessProPayment $payment): void
    {
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0, 'TerminalNumber' => 1000, 'LowProfileId' => $payment->provider_low_profile_id,
            'ReturnValue' => $payment->public_id, 'Operation' => 'ChargeAndCreateToken',
            'TranzactionInfo' => $this->transaction(111),
            'TokenInfo' => ['Token' => (string) Str::uuid(), 'CardYear' => 2030, 'CardMonth' => 12],
        ])]);
        app(BusinessProBillingService::class)->verify($payment);
    }

    private function transaction(int $id): array
    {
        return ['ResponseCode' => 0, 'TerminalNumber' => 1000, 'Amount' => 19, 'CoinId' => 1, 'TranzactionId' => $id, 'IsRefund' => false];
    }

    private function entitlements(): BusinessProEntitlementService
    {
        return app(BusinessProEntitlementService::class);
    }
}
