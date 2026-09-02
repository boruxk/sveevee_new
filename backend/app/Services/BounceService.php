<?php

namespace App\Services;

use App\Models\EmailDelivery;
use App\Models\EmailSuppression;
use Illuminate\Support\Facades\Log;

class BounceService
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function ingest(string $token, string $rawMessage): bool
    {
        $delivery = EmailDelivery::query()->with('user')->where('bounce_token', $token)->first();

        if (! $delivery) {
            Log::warning('Ignored bounce with an unknown delivery token.', ['token' => $token]);

            return false;
        }

        $classification = $this->classification($rawMessage);

        if ($classification === null || $delivery->status === EmailDelivery::STATUS_HARD_BOUNCED) {
            return true;
        }

        $diagnostic = $this->diagnostic($rawMessage);
        $hardBounce = $classification === 'hard';

        $delivery->forceFill([
            'status' => $hardBounce
                ? EmailDelivery::STATUS_HARD_BOUNCED
                : EmailDelivery::STATUS_SOFT_BOUNCED,
            'failure_reason' => $diagnostic,
            'bounced_at' => now(),
        ])->save();

        if (! $hardBounce) {
            return true;
        }

        EmailSuppression::query()->updateOrCreate(
            ['email' => $this->verification->normalize($delivery->recipient_email)],
            [
                'reason' => 'hard_bounce',
                'diagnostic' => $diagnostic,
                'source_delivery_id' => $delivery->id,
                'suppressed_at' => now(),
            ]
        );

        if ($delivery->user) {
            $this->verification->invalidateCurrentAddress($delivery->user, $delivery->recipient_email);
        }

        return true;
    }

    private function classification(string $rawMessage): ?string
    {
        preg_match_all('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})\s*$/mi', $rawMessage, $matches);

        foreach ($matches[1] ?? [] as $status) {
            if (str_starts_with($status, '5.')) {
                return 'hard';
            }
        }

        foreach ($matches[1] ?? [] as $status) {
            if (str_starts_with($status, '4.')) {
                return 'soft';
            }
        }

        return null;
    }

    private function diagnostic(string $rawMessage): ?string
    {
        if (! preg_match('/^Diagnostic-Code:\s*(.+)$/mi', $rawMessage, $matches)) {
            return null;
        }

        return mb_substr(trim($matches[1]), 0, 2000);
    }
}
