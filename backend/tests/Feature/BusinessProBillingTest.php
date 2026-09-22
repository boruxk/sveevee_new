<?php

namespace Tests\Feature;

use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\User;
use App\Services\Billing\BusinessProBillingException;
use App\Services\Billing\BusinessProBillingService;
use App\Services\Billing\CardcomClient;
use App\Services\BusinessProEntitlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessProBillingTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = '644757c6-22bb-43e1-9e03-cb7290bb0a6b';

    private const TOKEN = '60d412f8-598c-4201-9185-c8d32172f013';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-09-22 10:00:00'));
        config()->set([
            'business_pro.rollout' => 'private',
            'business_pro.environment' => 'sandbox',
            'business_pro.billing_enabled' => true,
            'business_pro.renewals_enabled' => true,
            'business_pro.amount_minor' => 4900,
            'business_pro.cardcom.terminal_number' => 1000,
            'business_pro.cardcom.api_name' => 'test-api-name',
            'business_pro.cardcom.production_enabled' => false,
            'business_pro.cardcom.allow_local_callbacks' => false,
            'business_pro.cardcom.frontend_url' => 'https://example.com',
            'business_pro.cardcom.webhook_url' => 'https://example.com/api/v1/billing/cardcom/webhook',
        ]);
    }

    public function test_checkout_requires_login_preview_access_and_the_owned_claimed_business_page(): void
    {
        [$user, $page] = $this->owner();
        Http::fake();
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertUnauthorized();
        $ordinary = User::factory()->create();
        Sanctum::actingAs($ordinary);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertNotFound();
        [$other] = $this->owner();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertNotFound();
        Sanctum::actingAs($user);
        $page->update(['is_unclaimed' => true]);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertNotFound();
        $page->update(['is_unclaimed' => false, 'type' => Page::TYPE_COMMUNITY]);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('business_pro_payments', 0);
    }

    #[DataProvider('invalidForms')]
    public function test_checkout_requires_explicit_consent_and_current_server_price(array $overrides, int $status): void
    {
        [$user, $page] = $this->owner();
        Sanctum::actingAs($user);
        Http::fake();
        $this->postJson('/api/v1/business-pro/checkout', [...$this->form($page), ...$overrides])->assertStatus($status);
        Http::assertNothingSent();
        $this->assertDatabaseCount('business_pro_payments', 0);
    }

    public static function invalidForms(): array
    {
        return [
            'consent refused' => [['consent' => false], 422],
            'consent missing' => [['consent' => null], 422],
            'stale price' => [['amount_minor' => 4800], 409],
            'unsupported currency' => [['currency' => 'USD'], 422],
            'negative price' => [['amount_minor' => -4900], 422],
        ];
    }

    public function test_checkout_is_idempotent_and_does_not_grant_access_before_verified_payment(): void
    {
        [$user, $page] = $this->owner();
        $this->fakeCheckout();
        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertOk();
        $second = $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertOk();
        $this->assertSame($first->json('data.payment.public_id'), $second->json('data.payment.public_id'));
        $this->assertSame(1, $this->requestCount('/LowProfile/Create'));
        $this->assertDatabaseCount('business_pro_payments', 1);
        $this->assertFalse($this->access($user, $page));
        $payment = BusinessProPayment::firstOrFail();
        $this->assertSame('monthly-v1', $payment->metadata['consent_version']);
        $this->assertNotEmpty($payment->metadata['consent_at']);
        $this->assertSame(4900, $payment->amount_minor);
        $this->assertSame('pending', $payment->status);
    }

    public function test_invalid_callback_configuration_fails_before_creating_a_payment_record(): void
    {
        [$user, $page] = $this->owner();
        Sanctum::actingAs($user);
        Http::fake();
        config()->set('business_pro.cardcom.webhook_url', 'http://localhost/webhook');
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('business_pro_payments', 0);
        $this->assertDatabaseCount('business_pro_subscriptions', 0);
    }

    public function test_billing_switch_blocks_new_checkout_without_network_calls(): void
    {
        [$user, $page] = $this->owner();
        Sanctum::actingAs($user);
        Http::fake();
        config()->set('business_pro.billing_enabled', false);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('business_pro_payments', 0);
    }

    public function test_webhook_retrieves_receipt_ignores_untrusted_body_and_duplicate_delivery_is_idempotent(): void
    {
        [$user, $page, $payment] = $this->pending();
        $this->fakeReceipt($payment);
        $this->postJson('/api/v1/billing/cardcom/webhook', [
            'LowProfileId' => self::PROFILE, 'ResponseCode' => 123,
            'ReturnValue' => 'forged-value', 'TranzactionInfo' => ['Amount' => 0],
        ])->assertOk()->assertJsonPath('data.received', true);
        $subscription = $payment->subscription->fresh();
        $end = $subscription->current_period_end->toIso8601String();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($this->access($user, $page));
        $this->postJson('/api/v1/billing/cardcom/webhook', ['LowProfileId' => self::PROFILE])->assertOk();
        $this->getJson('/api/v1/billing/cardcom/webhook?LowProfileId='.self::PROFILE)->assertOk();
        $this->assertSame(1, $this->requestCount('/LowProfile/GetLpResult'));
        $this->assertDatabaseCount('business_pro_payments', 1);
        $this->assertSame($end, $subscription->fresh()->current_period_end->toIso8601String());
        $this->assertSame(1, BusinessProPayment::where('status', 'paid')->count());
    }

    public function test_token_is_encrypted_and_never_exposed_by_account_or_public_webhook_responses(): void
    {
        [$user, $page, $payment] = $this->pending();
        $this->fakeReceipt($payment);
        $webhook = $this->postJson('/api/v1/billing/cardcom/webhook', ['LowProfileId' => self::PROFILE])->assertOk();
        $subscription = $payment->subscription->fresh();
        $this->assertSame(self::TOKEN, $subscription->provider_token);
        $raw = DB::table('business_pro_subscriptions')->where('id', $subscription->id)->value('provider_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertStringNotContainsString(self::TOKEN, $raw);
        Sanctum::actingAs($user);
        $overview = $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.has_access', true);
        foreach ([$webhook->getContent(), $overview->getContent(), $subscription->toJson(), $payment->fresh()->toJson()] as $json) {
            $this->assertStringNotContainsString(self::TOKEN, $json);
            $this->assertStringNotContainsString('test-api-name', $json);
            $this->assertStringNotContainsString('provider_token', $json);
        }
    }

    #[DataProvider('mismatchedReceipts')]
    public function test_receipts_must_match_payment_amount_currency_terminal_and_identity(string $field, mixed $value): void
    {
        [$user, $page, $payment] = $this->pending();
        $receipt = $this->receipt($payment);
        data_set($receipt, $field, $value);
        $this->fakeReceipt($payment, $receipt);
        $this->postJson('/api/v1/billing/cardcom/webhook', ['LowProfileId' => self::PROFILE])->assertStatus(409);
        $this->assertNotSame('paid', $payment->fresh()->status);
        $this->assertFalse($this->access($user, $page));
        $this->assertNull($payment->subscription->fresh()->provider_token);
    }

    public static function mismatchedReceipts(): array
    {
        return [
            'wrong order' => ['ReturnValue', '3e6e8f44-49f1-4f30-bec0-6baf4d2a9f42'],
            'wrong profile' => ['LowProfileId', '3e6e8f44-49f1-4f30-bec0-6baf4d2a9f42'],
            'wrong top terminal' => ['TerminalNumber', 1234],
            'wrong transaction terminal' => ['TranzactionInfo.TerminalNumber', 1234],
            'wrong amount' => ['TranzactionInfo.Amount', 1],
            'wrong amount precision' => ['TranzactionInfo.Amount', 49.001],
            'wrong currency' => ['TranzactionInfo.CoinId', 2],
            'refund' => ['TranzactionInfo.IsRefund', true],
            'refund not confirmed false' => ['TranzactionInfo.IsRefund', null],
            'no transaction id' => ['TranzactionInfo.TranzactionId', 0],
            'token-only operation' => ['Operation', 'CreateTokenOnly'],
        ];
    }

    #[DataProvider('unpaidStatuses')]
    public function test_declines_validation_and_authorization_holds_never_unlock_pro(int $outer, int $inner): void
    {
        [$user, $page, $payment] = $this->pending();
        $receipt = $this->receipt($payment);
        $receipt['ResponseCode'] = $outer;
        $receipt['TranzactionInfo']['ResponseCode'] = $inner;
        $this->fakeReceipt($payment, $receipt);
        $this->postJson('/api/v1/billing/cardcom/webhook', ['LowProfileId' => self::PROFILE])->assertOk();
        $this->assertFalse($this->access($user, $page));
        $this->assertNotSame('paid', $payment->fresh()->status);
    }

    public static function unpaidStatuses(): array
    {
        return ['unfinished' => [1, 0], 'declined' => [0, 4], 'validation' => [0, 700], 'authorization hold' => [0, 701]];
    }

    public function test_redirect_hint_and_foreign_account_cannot_verify_or_activate_payment(): void
    {
        [$user, $page, $payment] = $this->pending();
        [$other] = $this->owner();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/business-pro/payments/'.$payment->public_id.'/verify', ['result' => 'success'])->assertNotFound();
        $this->assertSame(0, $this->requestCount('/LowProfile/GetLpResult'));
        Sanctum::actingAs($user);
        $receipt = $this->receipt($payment);
        $receipt['ResponseCode'] = 1;
        $this->fakeReceipt($payment, $receipt);
        $this->postJson('/api/v1/business-pro/payments/'.$payment->public_id.'/verify', ['result' => 'success'])
            ->assertOk()->assertJsonPath('data.overview.has_access', false);
        $this->assertFalse($this->access($user, $page));
    }

    public function test_lost_checkout_response_recovers_from_verified_webhook_without_second_checkout(): void
    {
        [$user, $page] = $this->owner();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/LowProfile/Create')) {
                throw new ConnectionException('timeout with private provider body');
            }

            return Http::response($this->receipt(BusinessProPayment::firstOrFail()));
        });
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertStatus(503);
        $payment = BusinessProPayment::firstOrFail();
        $this->assertSame('unknown', $payment->status);
        $this->assertNull($payment->provider_low_profile_id);
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertStatus(409);
        $this->assertDatabaseCount('business_pro_payments', 1);
        $this->fakeReceipt($payment);
        $this->postJson('/api/v1/billing/cardcom/webhook', ['LowProfileId' => self::PROFILE])->assertOk();
        $this->assertSame(self::PROFILE, $payment->fresh()->provider_low_profile_id);
        $this->assertTrue($this->access($user, $page));
    }

    public function test_successful_initial_payment_without_token_honors_month_but_cannot_auto_renew(): void
    {
        [$user, $page, $payment] = $this->pending();
        $receipt = $this->receipt($payment);
        unset($receipt['TokenInfo']);
        $this->fakeReceipt($payment, $receipt);
        $this->billing()->verify($payment);
        $subscription = $payment->subscription->fresh();
        $this->assertTrue($this->access($user, $page));
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertNull($subscription->next_charge_at);
        $this->assertSame('token_missing', $payment->fresh()->failure_code);
    }

    public function test_paid_access_expires_and_cannot_be_created_by_status_flag_alone(): void
    {
        [$user, $page, $subscription] = $this->active();
        $this->assertTrue($this->access($user, $page));
        $this->travelTo($subscription->current_period_end->copy());
        $this->assertFalse($this->access($user, $page));
        $subscription->forceFill(['current_period_start' => now(), 'current_period_end' => now()->addMonth()])->save();
        $this->assertFalse($this->access($user, $page));
    }

    public function test_monthly_renewal_uses_frozen_subscription_price_and_single_period_idempotency_key(): void
    {
        [$user, $page, $subscription] = $this->active();
        $admin = User::factory()->create(['role' => 'admin']);
        app(BusinessProEntitlementService::class)->updateOffer(7900, $admin);
        $oldEnd = $subscription->current_period_end->copy();
        $this->travelTo($oldEnd->copy()->addSecond());
        $this->fakeCharge($this->transaction(222));
        $payment = $this->billing()->renew($subscription);
        $this->assertSame(4900, $payment->amount_minor);
        $this->assertSame('paid', $payment->status);
        $this->assertSame($oldEnd->toIso8601String(), $payment->period_start->toIso8601String());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Transactions/Transaction')
            && $request['Amount'] === 49.0 && $request['ExternalUniqTranId'] === $payment->idempotency_key);
        $this->assertNull($this->billing()->renew($subscription->fresh()));
        $this->assertSame(1, $this->requestCount('/Transactions/Transaction'));
        $this->assertTrue($this->access($user, $page));
    }

    public function test_calendar_month_end_never_overflows_into_an_extra_month(): void
    {
        $this->travelTo(Carbon::parse('2027-01-31 10:00:00'));
        [, , $subscription] = $this->active();
        $this->assertSame('2027-02-28 10:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));
    }

    public function test_cancellation_preserves_paid_access_until_end_and_prevents_renewal(): void
    {
        [$user, $page, $subscription] = $this->active();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/business-pro/cancel')->assertOk();
        $this->assertTrue($subscription->fresh()->cancel_at_period_end);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertTrue($this->access($user, $page));
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        $this->assertNull($this->billing()->renew($subscription->fresh()));
        $this->assertSame(0, $this->requestCount('/Transactions/Transaction'));
        $this->assertFalse($this->access($user, $page));
    }

    public function test_network_unknown_renewal_only_reconciles_same_id_without_submitting_charge_twice(): void
    {
        [, , $subscription] = $this->active();
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        $calls = [];
        $resolvedAtProvider = false;
        Http::fake(function (Request $request) use (&$calls, &$resolvedAtProvider) {
            $calls[] = [$request->url(), $request['ExternalUniqTranId']];
            if (str_ends_with($request->url(), '/Transactions/Transaction')) {
                throw new ConnectionException('ambiguous timeout');
            }

            return Http::response($resolvedAtProvider ? $this->transaction(222) : ['ResponseCode' => 999, 'Description' => 'Unresolved']);
        });
        $first = $this->billing()->renew($subscription);
        $this->assertSame('unknown', $first->status);
        $second = $this->billing()->renew($subscription->fresh());
        $third = $this->billing()->renew($subscription->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertCount(3, $calls);
        $this->assertSame(CardcomClient::BASE_URL.'/Transactions/Transaction', $calls[0][0]);
        $this->assertSame(CardcomClient::BASE_URL.'/Transactions/GetTransactionByExternalUniqTran', $calls[1][0]);
        $this->assertSame($calls[0][1], $calls[1][1]);
        $this->assertSame($calls[0][1], $calls[2][1]);
        $this->assertSame(1, BusinessProPayment::where('kind', 'renewal')->count());
        $resolvedAtProvider = true;
        $resolved = $this->billing()->renew($subscription->fresh());
        $this->assertSame('paid', $resolved->status);
        $this->assertSame($first->id, $resolved->id);
    }

    public function test_provider_duplicate_608_is_reconciled_with_the_original_key(): void
    {
        [, , $subscription] = $this->active();
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        Http::fake([
            CardcomClient::BASE_URL.'/Transactions/Transaction' => Http::response(['ResponseCode' => 608]),
            CardcomClient::BASE_URL.'/Transactions/GetTransactionByExternalUniqTran' => Http::response($this->transaction(222)),
        ]);
        $payment = $this->billing()->renew($subscription);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(1, $this->requestCount('/Transactions/Transaction'));
        $this->assertSame(1, $this->requestCount('/Transactions/GetTransactionByExternalUniqTran'));
    }

    public function test_declined_renewal_stops_automatic_retries_and_does_not_extend_access(): void
    {
        [$user, $page, $subscription] = $this->active();
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        $this->fakeCharge(['ResponseCode' => 4]);
        $payment = $this->billing()->renew($subscription);
        $this->assertSame('failed', $payment->status);
        $this->assertSame('past_due', $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertNull($this->billing()->renew($subscription->fresh()));
        $this->assertFalse($this->access($user, $page));
        $this->assertSame(1, $this->requestCount('/Transactions/Transaction'));
    }

    public function test_switching_environment_never_reuses_sandbox_payment_or_token(): void
    {
        [$user, $page, $subscription] = $this->active();
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        config()->set(['business_pro.environment' => 'production', 'business_pro.cardcom.terminal_number' => 1234, 'business_pro.cardcom.production_enabled' => true]);
        $this->assertFalse($this->access($user, $page));
        try {
            $this->billing()->renew($subscription);
            $this->fail('A sandbox token must never be used in production.');
        } catch (BusinessProBillingException $exception) {
            $this->assertSame('environment_mismatch', $exception->reason);
        }
        $this->assertSame(0, $this->requestCount('/Transactions/Transaction'));
    }

    public function test_changing_page_owner_or_banning_user_removes_access_and_stops_renewal(): void
    {
        [$user, $page, $subscription] = $this->active();
        $user->forceFill(['banned_at' => now()])->save();
        $this->assertFalse($this->access($user, $page));
        $user->forceFill(['banned_at' => null])->save();
        $page->update(['user_id' => User::factory()->create()->id]);
        $this->assertFalse($this->access($user, $page));
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        $this->assertNull($this->billing()->renew($subscription));
        $this->assertTrue($subscription->fresh()->cancel_at_period_end);
        $this->assertSame(0, $this->requestCount('/Transactions/Transaction'));
    }

    public function test_already_paid_account_cannot_start_another_checkout_for_second_charge(): void
    {
        [$user, $page] = $this->active();
        Sanctum::actingAs($user);
        $before = $this->requestCount('/LowProfile/Create');
        $this->postJson('/api/v1/business-pro/checkout', $this->form($page))->assertStatus(409);
        $this->assertSame($before, $this->requestCount('/LowProfile/Create'));
        $this->assertDatabaseCount('business_pro_payments', 1);
    }

    public function test_public_production_customer_without_tester_flag_can_renew_after_rollout(): void
    {
        config()->set([
            'business_pro.rollout' => 'public', 'business_pro.environment' => 'production',
            'business_pro.cardcom.terminal_number' => 1234, 'business_pro.cardcom.production_enabled' => true,
        ]);
        [$user, $page] = $this->owner();
        $user->forceFill(['business_pro_tester' => false])->save();
        $this->fakeCheckout();
        $payment = $this->billing()->checkout($user, $page, 4900, 'ILS', 'en');
        $receipt = $this->receipt($payment);
        $receipt['TerminalNumber'] = 1234;
        $receipt['TranzactionInfo']['TerminalNumber'] = 1234;
        $this->fakeReceipt($payment, $receipt);
        $this->billing()->verify($payment);
        $subscription = $payment->subscription->fresh();
        $this->assertTrue($this->access($user, $page));
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        $transaction = $this->transaction(222);
        $transaction['TerminalNumber'] = 1234;
        $this->fakeCharge($transaction);
        $renewal = $this->billing()->renew($subscription);
        $this->assertNotNull($renewal);
        $this->assertSame('paid', $renewal->status);
        $this->assertTrue($this->access($user, $page));
    }

    public function test_late_old_receipt_is_recorded_for_review_without_overwriting_newer_subscription(): void
    {
        [, , $subscription] = $this->active();
        $this->travelTo($subscription->current_period_end->copy()->addSecond());
        $this->fakeCharge(['ResponseCode' => 4]);
        $older = $this->billing()->renew($subscription);
        $newStart = now();
        $newEnd = now()->addMonthNoOverflow();
        $newer = BusinessProPayment::query()->forceCreate([
            'public_id' => (string) Str::uuid(), 'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id, 'page_id' => $subscription->page_id,
            'kind' => 'initial', 'status' => 'paid', 'environment' => 'sandbox', 'terminal_number' => 1000,
            'amount_minor' => 4900, 'currency' => 'ILS', 'idempotency_key' => (string) Str::uuid(),
            'period_start' => $newStart, 'period_end' => $newEnd, 'paid_at' => now(), 'provider_transaction_id' => '333',
        ]);
        $subscription->forceFill([
            'status' => 'active', 'current_period_start' => $newStart, 'current_period_end' => $newEnd,
            'next_charge_at' => $newEnd, 'last_payment_id' => $newer->id,
        ])->save();
        Http::fake([CardcomClient::BASE_URL.'/Transactions/GetTransactionByExternalUniqTran' => Http::response($this->transaction(222))]);
        $review = $this->billing()->reconcile($older);
        $this->assertSame('paid', $review->status);
        $this->assertSame('stale_payment_review', $review->failure_code);
        $subscription->refresh();
        $this->assertSame($newer->id, $subscription->last_payment_id);
        $this->assertSame($newEnd->toIso8601String(), $subscription->current_period_end->toIso8601String());
        $this->assertSame($newStart->toIso8601String(), $subscription->current_period_start->toIso8601String());
    }

    public function test_sandbox_paid_month_can_be_replaced_by_fresh_production_checkout_without_reusing_test_entitlement(): void
    {
        [$user, $page, $subscription] = $this->active();
        $oldReceipt = $subscription->payments()->where('status', 'paid')->firstOrFail();
        config()->set([
            'business_pro.environment' => 'production', 'business_pro.cardcom.terminal_number' => 1234,
            'business_pro.cardcom.production_enabled' => true,
        ]);
        $productionProfile = (string) Str::uuid();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/Create' => Http::response([
            'ResponseCode' => 0, 'LowProfileId' => $productionProfile,
            'Url' => 'https://secure.cardcom.solutions/EA/LPC6/1234/'.$productionProfile,
        ])]);
        $productionPayment = $this->billing()->checkout($user, $page, 4900, 'ILS', 'he');
        $subscription->refresh();
        $this->assertSame('production', $productionPayment->environment);
        $this->assertSame(1234, $productionPayment->terminal_number);
        $this->assertSame('pending', $subscription->status);
        $this->assertNull($subscription->provider_token);
        $this->assertNull($subscription->token_expires_month);
        $this->assertNull($subscription->token_expires_year);
        $this->assertNull($subscription->current_period_start);
        $this->assertNull($subscription->current_period_end);
        $this->assertNull($subscription->last_payment_id);
        $this->assertNull($subscription->next_charge_at);
        $this->assertFalse($this->access($user, $page));
        $this->assertSame('paid', $oldReceipt->fresh()->status);
        $this->assertSame('sandbox', $oldReceipt->fresh()->environment);
        $this->assertDatabaseCount('business_pro_payments', 2);
        $this->assertSame(1, $this->requestCount('/LowProfile/Create'));
    }

    #[DataProvider('unsafeBillingEnvironmentTransitions')]
    public function test_production_subscription_cannot_be_replaced_by_sandbox_or_another_terminal(string $environment, int $terminal): void
    {
        [$user, $page, $subscription] = $this->active();
        $subscription->forceFill(['environment' => 'production', 'terminal_number' => 1234])->save();
        $subscription->payments()->update(['environment' => 'production', 'terminal_number' => 1234]);
        config()->set([
            'business_pro.environment' => $environment, 'business_pro.cardcom.terminal_number' => $terminal,
            'business_pro.cardcom.production_enabled' => true,
        ]);
        try {
            $this->billing()->checkout($user, $page, 4900, 'ILS', 'he');
            $this->fail('A production contract must not be silently moved to a different environment or terminal.');
        } catch (BusinessProBillingException $exception) {
            $this->assertSame('environment_mismatch', $exception->reason);
        }
        $this->assertSame('production', $subscription->fresh()->environment);
        $this->assertSame(1234, $subscription->fresh()->terminal_number);
        $this->assertSame(0, $this->requestCount('/LowProfile/Create'));
        $this->assertDatabaseCount('business_pro_payments', 1);
    }

    public static function unsafeBillingEnvironmentTransitions(): array
    {
        return ['production to sandbox' => ['sandbox', 1000], 'different production terminal' => ['production', 5678]];
    }

    #[DataProvider('changedOwnershipStates')]
    public function test_payment_received_after_page_ownership_change_is_recorded_for_review_without_activation(string $change): void
    {
        [$user, $page, $payment] = $this->pending();
        if ($change === 'deleted') {
            $page->delete();
        } elseif ($change === 'unclaimed') {
            $page->update(['is_unclaimed' => true]);
        } else {
            $page->update(['user_id' => User::factory()->create()->id]);
        }
        $this->fakeReceipt($payment);
        $verified = $this->billing()->verify($payment->fresh());
        $subscription = $payment->subscription->fresh();
        $this->assertSame('paid', $verified->status);
        $this->assertSame('ownership_changed_review', $verified->failure_code);
        $this->assertNotSame('active', $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertNull($subscription->next_charge_at);
        $this->assertNull($subscription->provider_token);
        $this->assertNull($subscription->current_period_end);
        $this->assertFalse(app(BusinessProEntitlementService::class)->hasAccess($user->fresh()));
    }

    public static function changedOwnershipStates(): array
    {
        return ['new owner' => ['transferred'], 'page removed' => ['deleted'], 'unclaimed' => ['unclaimed']];
    }

    public function test_billing_command_rotates_past_failed_pending_receipt_instead_of_starving_next_payment(): void
    {
        config()->set('business_pro.renewals_enabled', false);
        [, , $first] = $this->queuedPayment(now()->subMinutes(20));
        [, , $second] = $this->queuedPayment(now()->subMinutes(10));
        $seen = [];
        Http::fake(function (Request $request) use ($first, &$seen) {
            $payment = BusinessProPayment::where('provider_low_profile_id', $request['LowProfileId'])->firstOrFail();
            $seen[] = $payment->id;
            $receipt = $this->receipt($payment);
            $receipt['LowProfileId'] = $payment->provider_low_profile_id;
            $receipt['TranzactionId'] = 1000 + $payment->id;
            $receipt['TranzactionInfo']['TranzactionId'] = 1000 + $payment->id;
            if ($payment->id === $first->id) {
                $receipt['TranzactionInfo']['Amount'] = 1;
            }

            return Http::response($receipt);
        });
        $this->artisan('business-pro:bill', ['--limit' => 1])->assertExitCode(0);
        $this->assertTrue($first->fresh()->updated_at->equalTo(now()));
        $this->artisan('business-pro:bill', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame([$first->id, $second->id], $seen);
        $this->assertNotSame('paid', $first->fresh()->status);
        $this->assertSame('paid', $second->fresh()->status);
    }

    public function test_billing_command_rotates_past_failed_due_account_without_changing_its_billing_period(): void
    {
        [, , $firstPayment] = $this->queuedPayment(now()->subMinutes(20));
        [, , $secondPayment] = $this->queuedPayment(now()->subMinutes(10));
        $first = $firstPayment->subscription;
        $second = $secondPayment->subscription;
        foreach ([$firstPayment, $secondPayment] as $index => $payment) {
            $payment->forceFill(['status' => 'paid', 'paid_at' => now()->subMonth(),
                'period_start' => now()->subMonth(), 'period_end' => now()->subMinutes(5)])->save();
            $payment->subscription->forceFill([
                'status' => 'active', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinutes(5),
                'next_charge_at' => now()->subMinutes(5), 'provider_token' => self::TOKEN,
                'token_expires_month' => 12, 'token_expires_year' => 2030, 'updated_at' => now()->subMinutes(20 - $index * 10),
            ])->save();
        }
        $first->refresh();
        $originalEnd = $first->current_period_end->toIso8601String();
        $seen = [];
        Http::fake(function (Request $request) use ($first, &$seen) {
            $payment = BusinessProPayment::where('idempotency_key', $request['ExternalUniqTranId'])->firstOrFail();
            $seen[] = $payment->subscription_id;
            $transaction = $this->transaction(2000 + $payment->id);
            if ($payment->subscription_id === $first->id) {
                $transaction['Amount'] = 1;
            }

            return Http::response($transaction);
        });
        $this->artisan('business-pro:bill', ['--limit' => 1])->assertExitCode(0);
        $this->assertTrue($first->fresh()->updated_at->equalTo(now()));
        $this->artisan('business-pro:bill', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame([$first->id, $second->id], $seen);
        $this->assertSame($originalEnd, $first->fresh()->current_period_end->toIso8601String());
        $this->assertSame('paid', $second->payments()->where('kind', 'renewal')->firstOrFail()->status);
    }

    private function queuedPayment(Carbon $lastChecked): array
    {
        [$user, $page] = $this->owner();
        $subscription = BusinessProSubscription::query()->forceCreate([
            'user_id' => $user->id, 'page_id' => $page->id, 'status' => 'pending', 'environment' => 'sandbox',
            'terminal_number' => 1000, 'amount_minor' => 4900, 'currency' => 'ILS',
        ]);
        $payment = BusinessProPayment::query()->forceCreate([
            'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'subscription_id' => $subscription->id, 'user_id' => $user->id, 'page_id' => $page->id,
            'kind' => 'initial', 'status' => 'pending', 'environment' => 'sandbox', 'terminal_number' => 1000,
            'amount_minor' => 4900, 'currency' => 'ILS', 'provider_low_profile_id' => (string) Str::uuid(),
            'updated_at' => $lastChecked,
        ]);

        return [$user, $page, $payment];
    }

    private function owner(): array
    {
        $user = User::factory()->create();
        $user->forceFill(['business_pro_tester' => true])->save();
        $page = Page::create(['user_id' => $user->id, 'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => false, 'name' => 'Pro Billing Test '.Str::random(8)]);

        return [$user, $page];
    }

    private function form(Page $page): array
    {
        return ['page_id' => $page->id, 'amount_minor' => 4900, 'currency' => 'ILS', 'consent' => true, 'locale' => 'he'];
    }

    private function fakeCheckout(): void
    {
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/Create' => Http::response([
            'ResponseCode' => 0, 'LowProfileId' => self::PROFILE,
            'Url' => 'https://secure.cardcom.solutions/EA/LPC6/1000/'.self::PROFILE,
        ])]);
    }

    private function pending(): array
    {
        [$user, $page] = $this->owner();
        $this->fakeCheckout();
        $payment = $this->billing()->checkout($user, $page, 4900, 'ILS', 'he');

        return [$user, $page, $payment];
    }

    private function active(): array
    {
        [$user, $page, $payment] = $this->pending();
        $this->fakeReceipt($payment);
        $this->billing()->verify($payment);

        return [$user, $page, $payment->subscription->fresh()];
    }

    private function fakeReceipt(BusinessProPayment $payment, ?array $receipt = null): void
    {
        Http::fake([CardcomClient::BASE_URL.'/LowProfile/GetLpResult' => Http::response($receipt ?? $this->receipt($payment))]);
    }

    private function receipt(BusinessProPayment $payment): array
    {
        return [
            'ResponseCode' => 0, 'TerminalNumber' => 1000, 'LowProfileId' => self::PROFILE,
            'ReturnValue' => $payment->public_id, 'Operation' => 'ChargeAndCreateToken',
            'TranzactionId' => 111, 'TranzactionInfo' => $this->transaction(111),
            'TokenInfo' => ['Token' => self::TOKEN, 'TokenExDate' => '20301231', 'CardYear' => 2030, 'CardMonth' => 12],
        ];
    }

    private function transaction(int $id): array
    {
        return ['ResponseCode' => 0, 'TerminalNumber' => 1000, 'Amount' => 49, 'CoinId' => 1, 'TranzactionId' => $id, 'IsRefund' => false];
    }

    private function fakeCharge(array $transaction): void
    {
        Http::fake([CardcomClient::BASE_URL.'/Transactions/Transaction' => Http::response($transaction)]);
    }

    private function requestCount(string $suffix): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), $suffix))->count();
    }

    private function access(User $user, Page $page): bool
    {
        return app(BusinessProEntitlementService::class)->hasAccess($user->fresh(), $page->fresh());
    }

    private function billing(): BusinessProBillingService
    {
        return app(BusinessProBillingService::class);
    }
}
