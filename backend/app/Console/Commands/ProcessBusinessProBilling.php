<?php

namespace App\Console\Commands;

use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Services\Billing\BusinessProBillingException;
use App\Services\Billing\BusinessProBillingService;
use Illuminate\Console\Command;

class ProcessBusinessProBilling extends Command
{
    protected $signature = 'business-pro:bill {--limit=100 : Maximum accounts or pending payments per pass}';

    protected $description = 'Reconcile Business Pro payments and renew due monthly subscriptions once per billing period';

    public function handle(BusinessProBillingService $billing): int
    {
        if (! config('business_pro.billing_enabled')) {
            $this->info('Business Pro billing is disabled.');

            return self::SUCCESS;
        }
        $limit = min(500, max(1, (int) $this->option('limit')));
        $environment = config('business_pro.environment');
        $terminal = (int) config('business_pro.cardcom.terminal_number');
        $checked = 0;
        $pending = BusinessProPayment::query()->where('environment', $environment)->where('terminal_number', $terminal)
            ->whereIn('status', ['pending', 'processing', 'unknown'])->where('updated_at', '<', now()->subMinutes(2))
            ->orderBy('updated_at')->limit($limit)->get();
        foreach ($pending as $payment) {
            try {
                $billing->verify($payment);
                $checked++;
            } catch (BusinessProBillingException $error) {
                $this->warn('Payment '.$payment->public_id.': '.$error->reason);
            } finally {
                // Failed receipts must not occupy the oldest slots forever and
                // prevent newer payments from receiving their first verification.
                $payment->touch();
            }
        }
        BusinessProSubscription::query()->where('cancel_at_period_end', true)
            ->where('current_period_end', '<=', now())->where('status', 'active')
            ->update(['status' => 'cancelled', 'next_charge_at' => null]);
        $renewed = 0;
        if (config('business_pro.renewals_enabled')) {
            $due = BusinessProSubscription::query()->where('environment', $environment)->where('terminal_number', $terminal)
                ->where('status', 'active')->where('cancel_at_period_end', false)
                ->where('next_charge_at', '<', now())->orderBy('updated_at')->orderBy('next_charge_at')->limit($limit)->get();
            foreach ($due as $subscription) {
                try {
                    $payment = $billing->renew($subscription);
                    $renewed += (int) ($payment?->status === 'paid');
                } catch (BusinessProBillingException $error) {
                    $this->warn('Subscription '.$subscription->id.': '.$error->reason);
                } finally {
                    // Rotate failed accounts too; renew() still reconciles the
                    // same idempotency key and never resubmits an ambiguous charge.
                    $subscription->touch();
                }
            }
        }
        $this->info("Checked {$checked} pending payments; {$renewed} paid renewals.");

        return self::SUCCESS;
    }
}
