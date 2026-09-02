<?php

namespace App\Observers;

use App\Jobs\SendUnreadChatEmail;
use App\Models\ChatEmailNotificationState;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatMessageObserver
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function created(ChatMessage $message): void
    {
        $conversation = $message->conversation()->first();

        if (! $conversation || $conversation->is_support) {
            return;
        }

        $recipientId = $conversation->user_one_id === $message->sender_id
            ? $conversation->user_two_id
            : $conversation->user_one_id;
        $recipient = User::query()->with('profile')->find($recipientId);

        if (! $recipient?->profile?->email_chat_notifications
            || ! $this->verification->canUseEmailFeatures($recipient)) {
            return;
        }

        $pendingToken = DB::transaction(function () use ($conversation, $recipient, $message): ?string {
            ChatEmailNotificationState::query()->insertOrIgnore([
                'conversation_id' => $conversation->id,
                'recipient_id' => $recipient->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = ChatEmailNotificationState::query()
                ->where('conversation_id', $conversation->id)
                ->where('recipient_id', $recipient->id)
                ->lockForUpdate()
                ->first();

            if (! $state || $state->pending_token || $state->notified_at) {
                return null;
            }

            $token = Str::random(48);
            $state->forceFill([
                'pending_message_id' => $message->id,
                'pending_token' => $token,
            ])->save();

            return $token;
        });

        if (! $pendingToken) {
            return;
        }

        SendUnreadChatEmail::dispatch($conversation->id, $recipient->id, $pendingToken)
            ->delay(now()->addMinutes((int) config('mail.chat_notifications.delay_minutes', 5)));
    }
}
