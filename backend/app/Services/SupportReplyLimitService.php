<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;

class SupportReplyLimitService
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function composerState(int $unansweredMessages): array
    {
        $limit = max(1, min(100, $this->settings->integer('chat.support_messages_before_reply', 5)));
        $remaining = max(0, $limit - max(0, $unansweredMessages));

        return [
            'can_send' => $remaining > 0,
            'reason' => $remaining === 0 ? 'support_pending_reply' : null,
            'message' => $remaining === 0 ? 'Please wait for a reply from support before sending more messages.' : null,
            'limit' => $limit,
            'remaining' => $remaining,
        ];
    }

    public function accountComposerState(User $user, Conversation $conversation): array
    {
        $conversation->loadMissing(['userOne', 'userTwo', 'messages']);
        $supportEmail = mb_strtolower((string) config('sveevee.support_admin_email'));

        if (mb_strtolower($user->email) === $supportEmail) {
            return ['can_send' => true, 'reason' => null, 'message' => null];
        }

        // Claimed guest history retains its timestamps, but gets new row IDs.
        $unanswered = $conversation->messages
            ->reject(fn ($message): bool => $message->is_automatic)
            ->sortByDesc(fn ($message): string => ($message->created_at?->format('Y-m-d H:i:s.u') ?? '').sprintf('%020d', $message->id))
            ->takeWhile(fn ($message): bool => $message->sender_id === $user->id)
            ->count();

        return $this->composerState($unanswered);
    }
}
