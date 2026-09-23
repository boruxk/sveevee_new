<?php

namespace App\Services;

use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessProEntitlementService
{
    public function canPreview(?User $user): bool
    {
        return $user !== null && $user->banned_at === null
            && ($user->hasRole('admin') || ($user->hasRole('user') && (bool) $user->business_pro_tester));
    }

    public function isVisible(?User $user): bool
    {
        return $user !== null && $user->banned_at === null
            && ($user->hasRole('admin') || $user->hasRole('user'));
    }

    public function canPurchase(User $user): bool
    {
        return $this->isVisible($user) && (bool) config('business_pro.billing_enabled', false)
            && ($this->canPreview($user) || (config('business_pro.environment') === 'production'
                && config('business_pro.rollout', 'private') === 'public'));
    }

    public function assertVisible(User $user): void
    {
        abort_unless($this->isVisible($user), 404);
    }

    public function assertOwnedBusiness(User $user, Page $page): void
    {
        $this->assertVisible($user);
        abort_unless($page->type === Page::TYPE_BUSINESS && ! $page->is_unclaimed
            && (int) $page->user_id === (int) $user->id, 404);
    }

    public function subscription(User $user): ?BusinessProSubscription
    {
        return BusinessProSubscription::query()->where('user_id', $user->id)->first();
    }

    public function hasAccess(User $user, ?Page $page = null): bool
    {
        if (! $this->isVisible($user)) {
            return false;
        }
        $query = $this->paidSubscriptions()->where('pro_subscription.user_id', $user->id);
        if ($page) {
            $query->where('pro_subscription.plan_key', 'business_pro')->where('pro_subscription.page_id', $page->id);
        }

        return $query->exists();
    }

    /**
     * One shared proof-of-payment predicate for account access and ranked ads.
     * It uses EXISTS rather than loading subscriptions for each result card.
     */
    private function paidSubscriptions(): QueryBuilder
    {
        return DB::table('business_pro_subscriptions as pro_subscription')
            ->join('users as pro_user', 'pro_user.id', '=', 'pro_subscription.user_id')
            ->whereNull('pro_user.banned_at')->whereIn('pro_user.role', ['user', 'admin'])
            ->whereIn('pro_subscription.status', ['active', 'cancelled'])
            ->where('pro_subscription.current_period_start', '<=', now())
            ->where('pro_subscription.current_period_end', '>', now())
            ->where('pro_subscription.environment', config('business_pro.environment', 'sandbox'))
            ->where('pro_subscription.terminal_number', (int) config('business_pro.cardcom.terminal_number', 1000))
            ->when(config('business_pro.environment', 'sandbox') === 'sandbox', fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $user) => $user->where('pro_user.role', 'admin')->orWhere('pro_user.business_pro_tester', true)))
            ->where(function (QueryBuilder $query): void {
                $query->where(fn (QueryBuilder $private) => $private->where('pro_subscription.plan_key', 'private_pro')
                    ->whereNull('pro_subscription.page_id'))
                    ->orWhere(fn (QueryBuilder $business) => $business->where('pro_subscription.plan_key', 'business_pro')
                        ->whereExists(fn (QueryBuilder $page) => $page->selectRaw('1')->from('pages as pro_page')
                            ->whereColumn('pro_page.id', 'pro_subscription.page_id')
                            ->whereColumn('pro_page.user_id', 'pro_subscription.user_id')
                            ->where('pro_page.type', Page::TYPE_BUSINESS)->where('pro_page.is_unclaimed', false)));
            })
            ->whereExists(function (QueryBuilder $payment): void {
                $payment->selectRaw('1')->from('business_pro_payments as pro_payment')
                    ->whereColumn('pro_payment.id', 'pro_subscription.last_payment_id')
                    ->whereColumn('pro_payment.subscription_id', 'pro_subscription.id')
                    ->whereColumn('pro_payment.user_id', 'pro_subscription.user_id')
                    ->whereColumn('pro_payment.plan_key', 'pro_subscription.plan_key')
                    ->where(fn (QueryBuilder $page) => $page->whereColumn('pro_payment.page_id', 'pro_subscription.page_id')
                        ->orWhere(fn (QueryBuilder $private) => $private->whereNull('pro_payment.page_id')->whereNull('pro_subscription.page_id')))
                    ->where('pro_payment.status', 'paid')->whereNotNull('pro_payment.paid_at')
                    ->whereColumn('pro_payment.environment', 'pro_subscription.environment')
                    ->whereColumn('pro_payment.terminal_number', 'pro_subscription.terminal_number')
                    ->whereColumn('pro_payment.currency', 'pro_subscription.currency')
                    ->whereColumn('pro_payment.amount_minor', 'pro_subscription.amount_minor')
                    ->whereColumn('pro_payment.period_start', '<=', 'pro_subscription.current_period_start')
                    ->whereColumn('pro_payment.period_end', '>=', 'pro_subscription.current_period_end');
            });
    }

    public function canFeatureAd(User $user, ?Page $page = null): bool
    {
        if (! $this->featuredAdsEnabled() || ! $this->hasAccess($user, $page)) {
            return false;
        }

        return $page === null || ((int) $page->user_id === (int) $user->id
            && $page->type === Page::TYPE_BUSINESS && ! $page->is_unclaimed);
    }

    public function applyFeaturedAdEntitlement(Builder $ads): Builder
    {
        if (! config('business_pro.features.featured_ads.implemented', false)) {
            return $ads->whereRaw('1 = 0');
        }
        $table = $ads->getModel()->getTable();
        $subscriptions = $this->paidSubscriptions()->selectRaw('1')
            ->whereColumn('pro_subscription.user_id', $table.'.user_id')
            ->where(fn (QueryBuilder $scope) => $scope->whereNull($table.'.page_id')
                ->orWhere(fn (QueryBuilder $business) => $business->where('pro_subscription.plan_key', 'business_pro')
                    ->whereColumn('pro_subscription.page_id', $table.'.page_id')));

        return $ads->whereExists(fn (QueryBuilder $feature) => $feature->selectRaw('1')
            ->from('business_pro_features')->where('key', 'featured_ads')->where('enabled', true)->where('lifecycle', 'published'))
            ->whereExists($subscriptions);
    }

    private function featuredAdsEnabled(): bool
    {
        return (bool) config('business_pro.features.featured_ads.implemented', false)
            && BusinessProFeature::query()->where('key', 'featured_ads')->where('enabled', true)->where('lifecycle', 'published')->exists();
    }

    public function offer(string $planKey = 'business_pro'): array
    {
        abort_unless(in_array($planKey, ['private_pro', 'business_pro'], true), 422);
        $stored = SystemSetting::query()->where('key', $planKey.'_offer')->value('value');
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        $defaultAmount = $planKey === 'private_pro'
            ? config('business_pro.private_amount_minor', 1900) : config('business_pro.amount_minor', 4900);

        return [
            'plan_key' => $planKey,
            'amount_minor' => (int) ($stored['amount_minor'] ?? $defaultAmount),
            'currency' => 'ILS', 'interval' => 'monthly',
            'included_pages' => $planKey === 'private_pro' ? 0 : 1,
            'environment' => config('business_pro.environment', 'sandbox'),
            'billing_enabled' => (bool) config('business_pro.billing_enabled', false),
        ];
    }

    public function updateOffer(int $amountMinor, User $admin, string $planKey = 'business_pro'): array
    {
        abort_unless($admin->hasRole('admin'), 404);
        $this->offer($planKey);
        SystemSetting::query()->updateOrCreate(['key' => $planKey.'_offer'], [
            'value' => ['amount_minor' => $amountMinor], 'updated_by_user_id' => $admin->id,
        ]);

        return $this->offer($planKey);
    }

    public function overview(User $user, ?Page $page = null): array
    {
        $this->assertVisible($user);
        if ($page) {
            $this->assertOwnedBusiness($user, $page);
        }
        $subscription = $this->subscription($user);
        $hasAccess = $this->hasAccess($user, $page);
        $pages = $user->pages()->where('type', Page::TYPE_BUSINESS)->where('is_unclaimed', false)
            ->orderBy('id')->get(['id', 'name']);
        // Do not offer an overlapping contract or pretend cancellation ends a paid month immediately.
        $sandboxToProduction = $subscription?->environment === 'sandbox' && config('business_pro.environment') === 'production';
        $environmentMatches = $subscription === null || $sandboxToProduction
            || ($subscription->environment === config('business_pro.environment')
                && $subscription->terminal_number === (int) config('business_pro.cardcom.terminal_number'));
        $hasPaidPeriod = ($subscription?->current_period_end?->isFuture() ?? false) && ! $sandboxToProduction;
        $pendingPayment = $subscription?->payments()->where('environment', config('business_pro.environment'))
            ->where('terminal_number', (int) config('business_pro.cardcom.terminal_number'))
            ->whereIn('status', ['pending', 'processing', 'unknown'])->latest('id')->first();
        $offers = collect(['private_pro', 'business_pro'])->map(function (string $planKey) use ($user, $subscription, $hasAccess, $hasPaidPeriod, $environmentMatches, $pendingPayment, $pages): array {
            $offer = $this->offer($planKey);
            $pendingMatches = $pendingPayment === null || ($pendingPayment->plan_key === $planKey
                && $pendingPayment->amount_minor === $offer['amount_minor'] && (bool) $pendingPayment->checkout_url);
            $canCheckout = $this->canPurchase($user) && $environmentMatches && ! $hasPaidPeriod && $pendingMatches
                && ($planKey === 'private_pro' || $pages->isNotEmpty());

            return [
                ...$offer,
                'can_checkout' => $canCheckout,
                'can_resume' => $canCheckout && $pendingPayment !== null,
                'features' => $this->features($user, $hasAccess && $subscription?->plan_key === $planKey, $planKey),
            ];
        })->all();

        return [
            'page' => $page ? ['id' => $page->id, 'name' => $page->name] : null,
            'pages' => $pages->map(fn (Page $entry): array => ['id' => $entry->id, 'name' => $entry->name])->all(),
            'offer' => $offers[1], 'offers' => $offers,
            'private_preview' => config('business_pro.rollout', 'private') !== 'public',
            'subscription' => $subscription ? $this->subscriptionPayload($subscription) : null,
            'pending_payment' => $pendingPayment ? $this->paymentPayload($pendingPayment) : null,
            'pending_plan_key' => $pendingPayment?->plan_key,
            'has_access' => $hasAccess,
            'can_checkout' => $offers[1]['can_checkout'],
            'can_cancel' => $subscription !== null && ! $subscription->cancel_at_period_end
                && in_array($subscription->status, ['active', 'past_due'], true),
            'features' => $this->features($user, $hasAccess, $subscription?->plan_key ?? 'business_pro'),
        ];
    }

    public function features(User $user, bool $hasAccess = false, string $planKey = 'business_pro'): array
    {
        if (! $this->isVisible($user)) {
            return [];
        }

        return BusinessProFeature::query()
            ->when(! $this->canPreview($user), fn ($query) => $query->where('lifecycle', 'published'))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (BusinessProFeature $feature): bool => in_array($planKey, (array) config("business_pro.features.{$feature->key}.plans", ['business_pro']), true))
            ->values()->map(fn (BusinessProFeature $feature): array => $this->featurePayload($feature, $hasAccess))->all();
    }

    public function featurePayload(BusinessProFeature $feature, bool $hasAccess = false): array
    {
        $implemented = (bool) config("business_pro.features.{$feature->key}.implemented", false);
        $reason = ! $implemented ? 'not_implemented'
            : (! $feature->enabled ? 'disabled' : (! $hasAccess ? 'subscription_required' : null));

        return [
            'id' => $feature->id,
            'key' => $feature->key,
            'labels' => $feature->labels,
            'plans' => (array) config("business_pro.features.{$feature->key}.plans", ['business_pro']),
            'descriptions' => $feature->descriptions ?? [],
            'lifecycle' => $feature->lifecycle,
            'enabled' => (bool) $feature->enabled,
            'implemented' => $implemented,
            'available' => $reason === null,
            'locked_reason' => $reason,
        ];
    }

    public function assertFeature(User $user, Page $page, string $key): void
    {
        $this->assertOwnedBusiness($user, $page);
        $feature = BusinessProFeature::query()->where('key', $key)->first();
        abort_unless($feature && ($feature->lifecycle === 'published' || $this->canPreview($user)), 404);
        $state = $this->featurePayload($feature, $this->hasAccess($user, $page));
        if (! $state['available']) {
            throw ValidationException::withMessages(['business_pro' => [$state['locked_reason']]])
                ->status($state['locked_reason'] === 'subscription_required' ? 402 : 403);
        }
    }

    public function subscriptionPayload(BusinessProSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'page_id' => $subscription->page_id,
            'plan_key' => $subscription->plan_key,
            'included_pages' => $subscription->included_pages,
            'status' => $subscription->status,
            'environment' => $subscription->environment,
            'amount_minor' => $subscription->amount_minor,
            'currency' => $subscription->currency,
            'interval' => $subscription->interval,
            'current_period_start' => $subscription->current_period_start?->toISOString(),
            'current_period_end' => $subscription->current_period_end?->toISOString(),
            'next_charge_at' => $subscription->next_charge_at?->toISOString(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'cancelled_at' => $subscription->cancelled_at?->toISOString(),
            'has_access' => $subscription->user !== null && $this->hasAccess($subscription->user),
        ];
    }

    public function paymentPayload(BusinessProPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'public_id' => $payment->public_id,
            'subscription_id' => $payment->subscription_id,
            'user_id' => $payment->user_id,
            'page_id' => $payment->page_id,
            'kind' => $payment->kind,
            'plan_key' => $payment->plan_key,
            'status' => $payment->status,
            'environment' => $payment->environment,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'created_at' => $payment->created_at?->toISOString(),
            'paid_at' => $payment->paid_at?->toISOString(),
            'failure_code' => $payment->failure_code,
        ];
    }
}
