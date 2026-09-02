<?php

namespace App\Jobs;

use App\Mail\VerifyEmailAddressMail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\EmailDeliveryService;
use App\Services\EmailVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SendEmailVerificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public string $bounceToken;

    public function __construct(public int $userId, public string $email)
    {
        $this->bounceToken = Str::random(48);
    }

    public function handle(
        EmailVerificationService $verification,
        EmailDeliveryService $deliveries,
    ): void {
        $user = User::query()->with('profile')->find($this->userId);

        if (! $user
            || $verification->normalize($user->email) !== $verification->normalize($this->email)
            || $verification->status($user) !== EmailVerificationService::STATUS_UNVERIFIED) {
            return;
        }

        $delivery = $deliveries->findOrCreate(
            $this->bounceToken,
            $user,
            'email_verification',
        );

        if ($delivery->status === EmailDelivery::STATUS_SENT) {
            return;
        }

        $verificationPath = URL::temporarySignedRoute(
            'email-verification.verify',
            now()->addHours(24),
            [
                'id' => $user->id,
                'hash' => sha1($verification->normalize($user->email)),
            ],
            absolute: false,
        );
        $verificationUrl = rtrim((string) config('app.url'), '/').$verificationPath;

        $deliveries->send(
            $delivery,
            $user->locale,
            new VerifyEmailAddressMail(
                verificationUrl: $verificationUrl,
                returnPath: $deliveries->bounceAddress($delivery->bounce_token),
                messageLocale: $user->locale,
            )
        );
    }

    public function backoff(): array
    {
        return [60, 300];
    }
}
