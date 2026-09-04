<?php

namespace App\Services;

use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class EmailDeliveryService
{
    public function findOrCreate(
        string $bounceToken,
        User $user,
        string $kind,
        ?int $conversationId = null,
        ?int $chatMessageId = null,
        ?string $notificationId = null,
    ): EmailDelivery {
        return EmailDelivery::query()->firstOrCreate(
            ['bounce_token' => $bounceToken],
            [
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'chat_message_id' => $chatMessageId,
                'notification_id' => $notificationId,
                'kind' => $kind,
                'recipient_email' => strtolower(trim($user->email)),
                'status' => EmailDelivery::STATUS_QUEUED,
            ]
        );
    }

    public function send(EmailDelivery $delivery, string $locale, Mailable $mailable): void
    {
        if (in_array($delivery->status, [
            EmailDelivery::STATUS_SENT,
            EmailDelivery::STATUS_HARD_BOUNCED,
        ], true)) {
            return;
        }

        $delivery->forceFill([
            'status' => EmailDelivery::STATUS_SENDING,
            'attempts' => $delivery->attempts + 1,
            'failure_reason' => null,
        ])->save();

        try {
            Mail::to($delivery->recipient_email)
                ->locale($locale)
                ->send($mailable);
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => EmailDelivery::STATUS_FAILED,
                'failure_reason' => Str::limit($exception->getMessage(), 2000, ''),
            ])->save();

            throw $exception;
        }

        $delivery->forceFill([
            'status' => EmailDelivery::STATUS_SENT,
            'sent_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function bounceAddress(string $token): string
    {
        return 'bounce+'.$token.'@'.config('mail.bounce_domain');
    }
}
