<?php

namespace App\Services;

use App\Models\GuestSupportConversation;
use App\Models\GuestSupportMessage;
use App\Models\User;
use Illuminate\Http\Request;

class GuestSupportService
{
    public const TOKEN_HEADER = 'X-Guest-Support-Token';

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function issueToken(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$token, $this->hashToken($token)];
    }

    public function fromRequest(Request $request, bool $includeClaimed = false): ?GuestSupportConversation
    {
        $token = trim((string) $request->header(self::TOKEN_HEADER, ''));

        if (strlen($token) < 32 || strlen($token) > 255) {
            return null;
        }

        return GuestSupportConversation::query()
            ->where('token_hash', $this->hashToken($token))
            ->when(! $includeClaimed, fn ($query) => $query->whereNull('claimed_at'))
            ->first();
    }

    public function payload(
        GuestSupportConversation $conversation,
        bool $withMessages = false,
        bool $forAdmin = false
    ): array {
        $conversation->loadMissing(['messages.sender.profile']);
        $participant = $this->participant($conversation);
        $latest = $conversation->messages
            ->sortBy(fn (GuestSupportMessage $message): string => sprintf(
                '%020s%020d',
                $message->created_at?->format('Uu') ?? '0',
                $message->id
            ))
            ->last();
        $unreadSender = $forAdmin
            ? GuestSupportMessage::SENDER_GUEST
            : GuestSupportMessage::SENDER_ADMIN;

        $payload = [
            'id' => $conversation->id,
            'support_key' => "guest:{$conversation->id}",
            'source' => 'guest',
            'participant' => $participant,
            'other_user' => $participant,
            'is_support' => true,
            'is_guest' => true,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'latest_message' => $latest ? $this->messagePayload($latest, $conversation) : null,
            'unread_count' => $conversation->messages
                ->where('sender_type', $unreadSender)
                ->whereNull('read_at')
                ->count(),
            'composer_state' => [
                'can_send' => true,
                'reason' => null,
                'message' => null,
            ],
        ];

        if ($withMessages) {
            $payload['messages'] = $conversation->messages
                ->map(fn (GuestSupportMessage $message): array => $this->messagePayload($message, $conversation))
                ->values();
        }

        return $payload;
    }

    public function markReadByGuest(GuestSupportConversation $conversation): void
    {
        $conversation->messages()
            ->where('sender_type', GuestSupportMessage::SENDER_ADMIN)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markReadByAdmin(GuestSupportConversation $conversation): void
    {
        $conversation->messages()
            ->where('sender_type', GuestSupportMessage::SENDER_GUEST)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function supportAdmin(): ?User
    {
        return User::query()
            ->where('email', config('sveevee.support_admin_email'))
            ->first();
    }

    private function participant(GuestSupportConversation $conversation): array
    {
        return [
            'id' => null,
            'display_name' => $conversation->name,
            'name' => $conversation->name,
            'email' => $conversation->email,
            'locale' => $conversation->locale,
            'profile' => null,
            'is_guest' => true,
        ];
    }

    private function messagePayload(
        GuestSupportMessage $message,
        GuestSupportConversation $conversation
    ): array {
        $guestSender = $message->sender_type === GuestSupportMessage::SENDER_GUEST;

        return [
            'id' => $message->id,
            'conversation_id' => $conversation->id,
            'sender_id' => $message->sender_user_id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'sender' => $guestSender
                ? $this->participant($conversation)
                : ($message->sender ? $this->payloads->user($message->sender) : null),
        ];
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
