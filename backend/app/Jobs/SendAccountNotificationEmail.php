<?php

namespace App\Jobs;

use App\Mail\AccountStatusMail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\EmailDeliveryService;
use App\Services\EmailVerificationService;
use App\Support\AccountNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAccountNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $notificationId) {}

    public function handle(
        EmailVerificationService $verification,
        EmailDeliveryService $deliveries,
    ): void {
        $notification = DatabaseNotification::query()->find($this->notificationId);

        if (! $notification || ! in_array($notification->type, AccountNotificationType::EMAIL_TYPES, true)) {
            return;
        }

        $recipient = $notification->notifiable;

        if (! $recipient instanceof User || ! $verification->canUseEmailFeatures($recipient)) {
            return;
        }

        $data = is_array($notification->data) ? $notification->data : [];
        $page = is_array($data['page'] ?? null) ? $data['page'] : [];
        $actionPath = str_starts_with((string) ($data['action_path'] ?? ''), '/')
            ? (string) $data['action_path']
            : '/me';
        $delivery = $deliveries->findOrCreate(
            hash('sha256', 'account-notification:'.$notification->id),
            $recipient,
            'account_'.$notification->type,
            notificationId: $notification->id,
        );

        if ($delivery->status === EmailDelivery::STATUS_SENT) {
            return;
        }

        $deliveries->send(
            $delivery,
            $recipient->locale,
            new AccountStatusMail(
                notificationType: $notification->type,
                pageName: (string) ($page['name'] ?? 'Sveevee'),
                actionUrl: rtrim((string) config('app.frontend_url'), '/').$actionPath,
                returnPath: $deliveries->bounceAddress($delivery->bounce_token),
                messageLocale: $recipient->locale,
                context: $data,
                notificationId: $notification->id,
            )
        );
    }

    public function backoff(): array
    {
        return [60, 300];
    }
}
