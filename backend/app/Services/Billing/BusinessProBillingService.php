<?php

namespace App\Services\Billing;

use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\User;
use App\Services\BusinessProEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessProBillingService
{
    public function __construct(private CardcomClient $cardcom, private BusinessProEntitlementService $entitlements) {}

    public function checkout(User $user, Page $page, int $expectedAmount, string $currency, string $locale): BusinessProPayment
    {
        $this->entitlements->assertVisible($user);
        $this->entitlements->assertOwnedBusiness($user, $page);
        $this->assertEnabled();
        $offer = $this->entitlements->offer();
        if ($expectedAmount !== $offer['amount_minor'] || $currency !== $offer['currency']) {
            throw new BusinessProBillingException('offer_changed');
        }
        try {
            $this->cardcom->validateCheckout($this->checkoutFields((string) Str::uuid(), $offer['amount_minor'], $locale));
        } catch (CardcomException $error) {
            throw new BusinessProBillingException('billing_unavailable', 503);
        }
        [$payment, $create] = DB::transaction(function () use ($user, $page, $offer, $locale) {
            // Lock the stable account row, including the first-ever checkout.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $subscription = BusinessProSubscription::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $environment = config('business_pro.environment');
            $terminal = (int) config('business_pro.cardcom.terminal_number');
            $sandboxToProduction = $subscription?->environment === 'sandbox' && $environment === 'production';
            if ($subscription && ! $sandboxToProduction
                && ($subscription->environment !== $environment || $subscription->terminal_number !== $terminal)) {
                // Never replace a real contract with sandbox data or silently move
                // saved production payment tokens to another merchant terminal.
                throw new BusinessProBillingException('environment_mismatch');
            }
            if (! $sandboxToProduction && $subscription?->current_period_end?->isFuture()) {
                throw new BusinessProBillingException('already_active');
            }
            if ($subscription) {
                $unfinished = $subscription->payments()->where('environment', $environment)
                    ->where('terminal_number', $terminal)->whereIn('status', ['pending', 'processing', 'unknown'])
                    ->orderByDesc('id')->first();
                if ($unfinished) {
                    if ($unfinished->page_id !== $page->id || $unfinished->amount_minor !== $offer['amount_minor']) {
                        throw new BusinessProBillingException('payment_pending');
                    }
                    $this->assertEnvironment($unfinished);

                    return [$unfinished, false];
                }
            }
            $subscription ??= new BusinessProSubscription;
            if ($sandboxToProduction) {
                // Preserve the sandbox payment history, but no simulated paid
                // period, token or receipt may grant or renew production access.
                $subscription->forceFill([
                    'current_period_start' => null, 'current_period_end' => null, 'last_payment_id' => null,
                    'provider_token' => null, 'provider_customer_id' => null,
                    'token_expires_month' => null, 'token_expires_year' => null,
                ]);
            }
            $subscription->forceFill([
                'user_id' => $user->id, 'page_id' => $page->id, 'plan_key' => 'business_pro', 'included_pages' => 1,
                'status' => 'pending', 'environment' => config('business_pro.environment'),
                'terminal_number' => config('business_pro.cardcom.terminal_number'),
                'amount_minor' => $offer['amount_minor'], 'currency' => 'ILS', 'interval' => 'monthly',
                'provider_token' => null, 'next_charge_at' => null, 'cancel_at_period_end' => false,
                'cancelled_at' => null,
            ])->save();
            $uuid = (string) Str::uuid();
            $payment = BusinessProPayment::query()->forceCreate([
                'subscription_id' => $subscription->id, 'user_id' => $user->id, 'page_id' => $page->id,
                'public_id' => $uuid, 'idempotency_key' => $uuid, 'kind' => 'initial', 'status' => 'processing',
                'amount_minor' => $offer['amount_minor'], 'currency' => 'ILS',
                'environment' => $subscription->environment, 'terminal_number' => $subscription->terminal_number,
                'metadata' => ['consent_at' => now()->toIso8601String(), 'consent_version' => 'monthly-v1', 'locale' => $locale],
            ]);

            return [$payment, true];
        });
        if (! $create) {
            if (! $payment->checkout_url) {
                throw new BusinessProBillingException('payment_pending');
            }

            return $payment;
        }
        try {
            $result = $this->cardcom->createCheckout($this->checkoutFields($payment->public_id, $payment->amount_minor, $locale));
        } catch (CardcomException $error) {
            $payment->forceFill(['status' => $error->ambiguous ? 'unknown' : 'failed', 'failure_code' => 'gateway_unavailable'])->save();
            throw new BusinessProBillingException('gateway_unavailable', 503);
        }
        if (! $this->zero($result['ResponseCode'] ?? null)) {
            $payment->forceFill(['status' => 'failed', 'failure_code' => 'checkout_rejected'])->save();
            throw new BusinessProBillingException('checkout_rejected', 422);
        }
        $url = $result['Url'] ?? '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! Str::isUuid($result['LowProfileId'] ?? '') || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! in_array($host, ['secure.cardcom.solutions', 'test.cardcom.solutions'], true)) {
            $payment->forceFill(['status' => 'unknown', 'failure_code' => 'invalid_checkout'])->save();
            throw new BusinessProBillingException('gateway_unavailable', 503);
        }
        $payment->forceFill(['status' => 'pending', 'provider_low_profile_id' => $result['LowProfileId'], 'checkout_url' => $url])->save();

        return $payment;
    }

    public function verify(BusinessProPayment $payment): BusinessProPayment
    {
        $this->assertEnvironment($payment);
        if ($payment->status === 'paid') {
            return $payment;
        }
        if ($payment->kind === 'renewal') {
            return $this->reconcile($payment);
        }
        if (! $payment->provider_low_profile_id) {
            return $payment;
        }
        try {
            $result = $this->cardcom->lowProfileResult($payment->provider_low_profile_id);
        } catch (CardcomException $error) {
            throw new BusinessProBillingException('gateway_unavailable', 503);
        }

        return $this->acceptLowProfile($payment, $result);
    }

    public function webhook(string $lowProfileId): ?BusinessProPayment
    {
        $payment = BusinessProPayment::query()->where('provider_low_profile_id', $lowProfileId)->first();
        if ($payment) {
            return $this->verify($payment);
        }
        // Recover a checkout whose response was lost after Cardcom created it.
        try {
            $result = $this->cardcom->lowProfileResult($lowProfileId);
        } catch (CardcomException $error) {
            throw new BusinessProBillingException('gateway_unavailable', 503);
        }
        $publicId = $result['ReturnValue'] ?? '';
        if (! Str::isUuid($publicId)) {
            return null;
        }
        $payment = BusinessProPayment::query()->where('public_id', $publicId)->where('kind', 'initial')->first();
        if (! $payment || ($payment->provider_low_profile_id && $payment->provider_low_profile_id !== $lowProfileId)) {
            return null;
        }
        $this->assertEnvironment($payment);
        if (($result['LowProfileId'] ?? null) !== $lowProfileId) {
            throw new BusinessProBillingException('receipt_mismatch');
        }

        return $this->acceptLowProfile($payment, $result);
    }

    private function acceptLowProfile(BusinessProPayment $payment, array $result): BusinessProPayment
    {
        if (($result['ReturnValue'] ?? null) !== $payment->public_id
            || (int) ($result['TerminalNumber'] ?? 0) !== $payment->terminal_number
            || ($payment->provider_low_profile_id && ($result['LowProfileId'] ?? null) !== $payment->provider_low_profile_id)) {
            throw new BusinessProBillingException('receipt_mismatch');
        }
        if (! $this->zero($result['ResponseCode'] ?? null) || ! is_array($result['TranzactionInfo'] ?? null)) {
            return $payment; // Still on the hosted payment page; never trust redirect hints.
        }
        if (($result['Operation'] ?? null) !== 'ChargeAndCreateToken') {
            throw new BusinessProBillingException('receipt_mismatch');
        }
        $transaction = $result['TranzactionInfo'];
        if (! $this->zero($transaction['ResponseCode'] ?? null)) {
            // A hosted page can allow another card attempt. Keep the same checkout.
            $payment->forceFill(['failure_code' => 'payment_declined'])->save();

            return $payment;
        }

        return $this->complete($payment, $transaction, $result['TokenInfo'] ?? [], $result['LowProfileId'] ?? null);
    }

    public function cancel(User $user): ?BusinessProSubscription
    {
        return DB::transaction(function () use ($user) {
            $subscription = BusinessProSubscription::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($subscription) {
                $subscription->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => now(), 'next_charge_at' => null])->save();
            }

            return $subscription;
        });
    }

    public function renew(BusinessProSubscription $subscription): ?BusinessProPayment
    {
        $this->assertEnabled();
        if (! config('business_pro.renewals_enabled')) {
            return null;
        }
        [$payment, $charge] = DB::transaction(function () use ($subscription) {
            $subscription = BusinessProSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $this->assertEnvironment($subscription);
            $user = $subscription->user;
            $page = $subscription->page;
            if ($subscription->cancel_at_period_end || ! $subscription->next_charge_at?->isPast()
                || $subscription->status !== 'active') {
                return [null, false];
            }
            if (! $user || $user->banned_at || ! $this->entitlements->isVisible($user)
                || ($subscription->environment === 'sandbox' && ! $this->entitlements->canPreview($user))
                || ! $page || $page->user_id !== $user->id || $page->is_unclaimed || $page->type !== Page::TYPE_BUSINESS) {
                $subscription->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => now(), 'next_charge_at' => null])->save();

                return [null, false];
            }
            // Avoid silently collecting months of back charges after an outage.
            if ($subscription->next_charge_at->lt(now()->subDays(7)) || ! $subscription->provider_token) {
                $subscription->forceFill(['status' => 'past_due', 'next_charge_at' => null])->save();

                return [null, false];
            }
            $key = 'pro-'.$subscription->id.'-'.$subscription->current_period_end->timestamp;
            $existing = $subscription->payments()->where('idempotency_key', $key)->first();
            if ($existing) {
                return [$existing, false];
            }
            $payment = BusinessProPayment::query()->forceCreate([
                'public_id' => (string) Str::uuid(), 'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id, 'page_id' => $subscription->page_id,
                'kind' => 'renewal', 'status' => 'processing', 'environment' => $subscription->environment,
                'terminal_number' => $subscription->terminal_number, 'amount_minor' => $subscription->amount_minor,
                'currency' => $subscription->currency, 'idempotency_key' => $key,
                'period_start' => $subscription->current_period_end,
                'period_end' => $subscription->current_period_end->copy()->addMonthNoOverflow(),
            ]);

            return [$payment, true];
        });
        if (! $payment) {
            return null;
        }
        if (! $charge) {
            return $payment->status === 'paid' ? $payment : $this->reconcile($payment);
        }
        $subscription->refresh();
        if ($subscription->cancel_at_period_end) {
            $payment->forceFill(['status' => 'failed', 'failure_code' => 'cancelled_before_charge'])->save();

            return $payment;
        }
        try {
            $result = $this->cardcom->chargeToken([
                'Token' => $subscription->provider_token,
                'CardExpirationMMYY' => sprintf('%02d%02d', $subscription->token_expires_month, $subscription->token_expires_year % 100),
                'Amount' => $payment->amount_minor / 100, 'ISOCoinId' => 1,
                'ExternalUniqTranId' => $payment->idempotency_key,
                'Advanced' => ['IsAutoRecurringPayment' => (bool) config('business_pro.cardcom.auto_recurring_terminal')],
            ]);
        } catch (CardcomException $error) {
            $payment->forceFill(['status' => 'unknown', 'failure_code' => 'gateway_unavailable'])->save();

            return $payment; // Reconcile by the SAME id; never submit another charge.
        }
        if ((int) ($result['ResponseCode'] ?? -1) === 608) {
            return $this->reconcile($payment);
        }
        if (! $this->zero($result['ResponseCode'] ?? null)) {
            $payment->forceFill(['status' => 'failed', 'failure_code' => 'payment_declined'])->save();
            $subscription->forceFill(['status' => 'past_due', 'next_charge_at' => null])->save();

            return $payment;
        }

        return $this->complete($payment, $result);
    }

    public function reconcile(BusinessProPayment $payment): BusinessProPayment
    {
        $this->assertEnvironment($payment);
        if ($payment->status === 'paid') {
            return $payment;
        }
        try {
            $result = $this->cardcom->transactionByExternalId($payment->idempotency_key);
        } catch (CardcomException $error) {
            return $payment;
        }

        return $this->zero($result['ResponseCode'] ?? null) ? $this->complete($payment, $result) : $payment;
    }

    private function complete(BusinessProPayment $payment, array $transaction, array $token = [], ?string $lowProfileId = null): BusinessProPayment
    {
        $this->assertEnvironment($payment);
        $amount = $transaction['Amount'] ?? null;
        if (! $this->zero($transaction['ResponseCode'] ?? null) || ! is_numeric($amount)
            || abs((float) $amount * 100 - $payment->amount_minor) > 0.001
            || (int) ($transaction['TerminalNumber'] ?? 0) !== $payment->terminal_number
            || ! in_array($transaction['CoinId'] ?? null, [1, '1', 376, '376'], true)
            || ($transaction['IsRefund'] ?? null) !== false
            || ! is_numeric($transaction['TranzactionId'] ?? null) || $transaction['TranzactionId'] <= 0) {
            throw new BusinessProBillingException('receipt_mismatch');
        }
        if ($lowProfileId !== null && ! Str::isUuid($lowProfileId)) {
            throw new BusinessProBillingException('receipt_mismatch');
        }

        return DB::transaction(function () use ($payment, $transaction, $token, $lowProfileId) {
            $subscription = BusinessProSubscription::query()->whereKey($payment->subscription_id)->lockForUpdate()->firstOrFail();
            $payment = BusinessProPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'paid') {
                return $payment;
            }
            $stale = $subscription->user_id !== $payment->user_id || $subscription->page_id !== $payment->page_id
                || $subscription->environment !== $payment->environment
                || $subscription->terminal_number !== $payment->terminal_number
                || $subscription->amount_minor !== $payment->amount_minor
                || $subscription->payments()->where('id', '>', $payment->id)->exists()
                || ($payment->kind === 'renewal' && $subscription->current_period_end && $payment->period_start
                    && ! $subscription->current_period_end->equalTo($payment->period_start));
            $start = $payment->period_start ?? now();
            $end = $payment->period_end ?? $start->copy()->addMonthNoOverflow();
            $payment->forceFill([
                'status' => 'paid', 'paid_at' => now(), 'failure_code' => null,
                'provider_transaction_id' => (string) $transaction['TranzactionId'],
                'period_start' => $start, 'period_end' => $end,
                'provider_low_profile_id' => $lowProfileId ?? $payment->provider_low_profile_id,
            ])->save();
            if ($stale) {
                // Record the money receipt for admin review without reviving an old
                // contract or moving a newer paid-through date backwards.
                $payment->forceFill(['failure_code' => 'stale_payment_review'])->save();

                return $payment;
            }
            $page = $subscription->page_id
                ? Page::query()->whereKey($subscription->page_id)->lockForUpdate()->first()
                : null;
            if (! $page || (int) $page->user_id !== (int) $subscription->user_id
                || $page->is_unclaimed || $page->type !== Page::TYPE_BUSINESS) {
                // A valid receipt still records money received. A page reassigned
                // or removed while the hosted checkout was open needs review,
                // not an unusable active subscription or another future charge.
                $payment->forceFill(['failure_code' => 'ownership_changed_review'])->save();
                $subscription->forceFill([
                    'status' => 'cancelled', 'cancel_at_period_end' => true,
                    'cancelled_at' => now(), 'next_charge_at' => null,
                ])->save();

                return $payment;
            }
            $changes = [
                'status' => 'active', 'current_period_start' => $start, 'current_period_end' => $end,
                'last_payment_id' => $payment->id,
            ];
            if ($payment->kind === 'initial') {
                $month = (int) ($token['CardMonth'] ?? 0);
                $year = (int) ($token['CardYear'] ?? 0);
                if ($year > 0 && $year < 100) {
                    $year += 2000;
                }
                if (Str::isUuid($token['Token'] ?? '') && $month >= 1 && $month <= 12 && $year >= now()->year) {
                    $changes += ['provider_token' => $token['Token'], 'token_expires_month' => $month, 'token_expires_year' => $year];
                } else {
                    // The first paid month is honored even if token creation failed.
                    $changes['cancel_at_period_end'] = true;
                    $payment->forceFill(['failure_code' => 'token_missing'])->save();
                }
            }
            $changes['next_charge_at'] = ($subscription->cancel_at_period_end || ($changes['cancel_at_period_end'] ?? false)) ? null : $end;
            $subscription->forceFill($changes)->save();

            return $payment;
        });
    }

    private function checkoutFields(string $publicId, int $amountMinor, string $locale): array
    {
        $returnUrl = rtrim(config('business_pro.cardcom.frontend_url'), '/').'/business-pro/payment/'.$publicId;

        return [
            'Amount' => $amountMinor / 100, 'ReturnValue' => $publicId,
            'ProductName' => 'Sveevee Business Pro - monthly',
            'Language' => in_array($locale, ['he', 'en', 'ru'], true) ? $locale : 'en',
            'SuccessRedirectUrl' => $returnUrl.'?result=success',
            'FailedRedirectUrl' => $returnUrl.'?result=failure',
            'CancelRedirectUrl' => $returnUrl.'?result=cancel',
            'WebHookUrl' => config('business_pro.cardcom.webhook_url') ?: rtrim(config('app.url'), '/').'/api/v1/billing/cardcom/webhook',
        ];
    }

    private function assertEnabled(): void
    {
        if (! config('business_pro.billing_enabled')) {
            throw new BusinessProBillingException('billing_unavailable', 503);
        }
    }

    private function assertEnvironment(BusinessProPayment|BusinessProSubscription $record): void
    {
        if ($record->environment !== config('business_pro.environment')
            || $record->terminal_number !== (int) config('business_pro.cardcom.terminal_number')) {
            throw new BusinessProBillingException('environment_mismatch');
        }
    }

    private function zero(mixed $value): bool
    {
        return $value === 0 || $value === '0';
    }
}
