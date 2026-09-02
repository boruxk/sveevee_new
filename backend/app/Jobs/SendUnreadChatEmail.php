<?php

namespace App\Jobs;

use App\Mail\UnreadChatMessageMail;
use App\Models\ChatEmailNotificationState;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\EmailDeliveryService;
use App\Services\EmailVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendUnreadChatEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $conversationId,
        public int $recipientId,
        public string $pendingToken,
    ) {}

    public function handle(
        EmailVerificationService $verification,
        EmailDeliveryService $deliveries,
    ): void {
        $state = ChatEmailNotificationState::query()
            ->where('conversation_id', $this->conversationId)
            ->where('recipient_id', $this->recipientId)
            ->where('pending_token', $this->pendingToken)
            ->first();
        $conversation = Conversation::query()->find($this->conversationId);
        $recipient = User::query()->with('profile')->find($this->recipientId);

        if (! $state || ! $conversation || $conversation->is_support || ! $recipient
            || ! $recipient->profile?->email_chat_notifications
            || ! $verification->canUseEmailFeatures($recipient)) {
            $this->discardPendingState();

            return;
        }

        $unreadMessage = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $recipient->id)
            ->whereNull('read_at')
            ->latest()
            ->first();

        if (! $unreadMessage) {
            $this->discardPendingState();

            return;
        }

        $sender = User::query()->find($unreadMessage->sender_id);

        if (! $sender) {
            $this->discardPendingState();

            return;
        }

        $delivery = $deliveries->findOrCreate(
            $this->pendingToken,
            $recipient,
            'chat_unread',
            $conversation->id,
            $unreadMessage->id,
        );

        if ($delivery->status !== EmailDelivery::STATUS_SENT) {
            $chatUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/me?'.http_build_query(['chatWith' => $sender->id]);

            $deliveries->send(
                $delivery,
                $recipient->locale,
                new UnreadChatMessageMail(
                    senderName: $sender->display_name,
                    chatUrl: $chatUrl,
                    returnPath: $deliveries->bounceAddress($delivery->bounce_token),
                    messageLocale: $recipient->locale,
                )
            );
        }

        ChatEmailNotificationState::query()
            ->whereKey($state->id)
            ->where('pending_token', $this->pendingToken)
            ->update([
                'pending_token' => null,
                'pending_message_id' => null,
                'notified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    private function discardPendingState(): void
    {
        ChatEmailNotificationState::query()
            ->where('conversation_id', $this->conversationId)
            ->where('recipient_id', $this->recipientId)
            ->where('pending_token', $this->pendingToken)
            ->delete();
    }
}
