<?php

namespace App\Services;

use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\SystemSetting;
use App\Models\User;
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
        return $this->canPreview($user)
            || ($user !== null && $user->banned_at === null && $user->hasRole('user')
                && config('business_pro.rollout', 'private') === 'public');
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

        $subscription = $this->subscription($user);
        if (! $subscription || ! in_array($subscription->status, ['active', 'cancelled'], true)
            || ! $subscription->current_period_start || $subscription->current_period_start->isFuture()
            || ! $subscription->current_period_end || ! $subscription->current_period_end->isFuture()
            || $subscription->environment !== config('business_pro.environment', 'sandbox')
            || $subscription->terminal_number !== (int) config('business_pro.cardcom.terminal_number', 1000)
            || ($subscription->environment === 'sandbox' && ! $this->canPreview($user))) {
            return false;
        }

        $page ??= $subscription->page;
        if (! $page || $page->type !== Page::TYPE_BUSINESS || $page->is_unclaimed
            || (int) $page->user_id !== (int) $user->id
            || (int) $subscription->page_id !== (int) $page->id) {
            return false;
        }

        // An admin/tester flag, status edit or forged checkout redirect is not proof of payment.
        return $subscription->payments()->whereKey($subscription->last_payment_id)
            ->where('page_id', $page->id)->where('status', 'paid')->whereNotNull('paid_at')
            ->where('user_id', $user->id)->where('environment', $subscription->environment)
            ->where('terminal_number', $subscription->terminal_number)
            ->where('currency', $subscription->currency)->where('amount_minor', $subscription->amount_minor)
            ->where('period_start', '<=', $subscription->current_period_start)
            ->where('period_end', '>=', $subscription->current_period_end)->exists();
    }

    public function offer(): array
    {
        $stored = SystemSetting::query()->where('key', 'business_pro_offer')->value('value');
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        $amount = (int) ($stored['amount_minor'] ?? config('business_pro.amount_minor', 4900));

        return [
            'amount_minor' => $amount,
            'currency' => 'ILS',
            'interval' => 'monthly',
            'included_pages' => 1,
            'environment' => config('business_pro.environment', 'sandbox'),
            'billing_enabled' => (bool) config('business_pro.billing_enabled', false),
        ];
    }

    public function updateOffer(int $amountMinor, User $admin): array
    {
        abort_unless($admin->hasRole('admin'), 404);
        SystemSetting::query()->updateOrCreate(['key' => 'business_pro_offer'], [
            'value' => ['amount_minor' => $amountMinor], 'updated_by_user_id' => $admin->id,
        ]);

        return $this->offer();
    }

    public function overview(User $user, ?Page $page = null): array
    {
        $this->assertVisible($user);
        if ($page) {
            $this->assertOwnedBusiness($user, $page);
        }
        $subscription = $this->subscription($user);
        $hasAccess = $this->hasAccess($user, $page);
        $offer = $this->offer();
        $pages = $user->pages()->where('type', Page::TYPE_BUSINESS)->where('is_unclaimed', false)
            ->orderBy('id')->get(['id', 'name']);

        return [
            'page' => $page ? ['id' => $page->id, 'name' => $page->name] : null,
            'pages' => $pages->map(fn (Page $entry): array => ['id' => $entry->id, 'name' => $entry->name])->all(),
            'offer' => $offer,
            'private_preview' => config('business_pro.rollout', 'private') !== 'public',
            'subscription' => $subscription ? $this->subscriptionPayload($subscription) : null,
            'has_access' => $hasAccess,
            'can_checkout' => $offer['billing_enabled'] && $pages->isNotEmpty() && ! $this->hasAccess($user),
            'can_cancel' => $subscription !== null && ! $subscription->cancel_at_period_end
                && in_array($subscription->status, ['active', 'past_due'], true),
            'features' => $this->features($user, $hasAccess),
        ];
    }

    public function features(User $user, bool $hasAccess = false): array
    {
        if (! $this->isVisible($user)) {
            return [];
        }

        return BusinessProFeature::query()
            ->when(! $this->canPreview($user), fn ($query) => $query->where('lifecycle', 'published'))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (BusinessProFeature $feature): array => $this->featurePayload($feature, $hasAccess))->all();
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
