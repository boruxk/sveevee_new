<?php

namespace App\Services;

use App\Models\ChatEmailNotificationState;
use App\Models\EmailDelivery;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmailVerificationService
{
    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_BOUNCED = 'bounced';

    public function status(User $user): string
    {
        if ($this->isSuppressed($user->email)) {
            return self::STATUS_BOUNCED;
        }

        return $user->hasVerifiedEmail()
            ? self::STATUS_VERIFIED
            : self::STATUS_UNVERIFIED;
    }

    public function payload(User $user): array
    {
        $status = $this->status($user);
        $lastSentAt = EmailDelivery::query()
            ->where('user_id', $user->id)
            ->where('kind', 'email_verification')
            ->where('recipient_email', $this->normalize($user->email))
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->value('sent_at');

        return [
            'status' => $status,
            'verified_at' => $user->email_verified_at?->toISOString(),
            'can_resend' => $status === self::STATUS_UNVERIFIED,
            'last_sent_at' => $lastSentAt,
        ];
    }

    public function canUseEmailFeatures(User $user): bool
    {
        return ! $user->banned_at
            && $this->status($user) === self::STATUS_VERIFIED;
    }

    public function isSuppressed(string $email): bool
    {
        return EmailSuppression::query()
            ->where('email', $this->normalize($email))
            ->exists();
    }

    public function invalidateCurrentAddress(User $user, string $email): bool
    {
        $normalizedEmail = $this->normalize($email);

        return DB::transaction(function () use ($user, $normalizedEmail): bool {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedUser || $this->normalize($lockedUser->email) !== $normalizedEmail) {
                return false;
            }

            $lockedUser->forceFill(['email_verified_at' => null])->save();
            $lockedUser->profile()->updateOrCreate([], ['email_chat_notifications' => false]);
            ChatEmailNotificationState::query()->where('recipient_id', $lockedUser->id)->delete();

            return true;
        });
    }

    public function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
