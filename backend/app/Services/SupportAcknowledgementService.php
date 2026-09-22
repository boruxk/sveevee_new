<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\GuestSupportConversation;
use App\Models\GuestSupportMessage;
use App\Models\User;

class SupportAcknowledgementService
{
    // Call only after a visitor message was accepted, inside its conversation lock.
    public function account(Conversation $conversation, User $visitor): ?ChatMessage
    {
        if (! $conversation->is_support) {
            return null;
        }

        $admin = $this->supportAdmin();
        if (! $admin || $admin->id === $visitor->id
            || ! in_array($admin->id, [$conversation->user_one_id, $conversation->user_two_id], true)) {
            return null;
        }

        // Claimed guest messages have new IDs but retain their original timestamps.
        $lastSupportMessage = $conversation->messages()
            ->where('sender_id', $admin->id)
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($lastSupportMessage?->is_automatic) {
            return null;
        }

        return $conversation->messages()->create([
            'sender_id' => $admin->id,
            'body' => $this->body($visitor->locale),
            'is_automatic' => true,
        ]);
    }

    public function guest(GuestSupportConversation $conversation): ?GuestSupportMessage
    {
        $admin = $this->supportAdmin();
        if (! $admin) {
            return null;
        }

        $lastSupportMessage = $conversation->messages()
            ->where('sender_type', GuestSupportMessage::SENDER_ADMIN)
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($lastSupportMessage?->is_automatic) {
            return null;
        }

        return $conversation->messages()->create([
            'sender_type' => GuestSupportMessage::SENDER_ADMIN,
            'sender_user_id' => $admin->id,
            'body' => $this->body($conversation->locale),
            'is_automatic' => true,
        ]);
    }

    private function supportAdmin(): ?User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->whereNull('banned_at')->first();
    }

    private function body(?string $locale): string
    {
        return match ($locale) {
            'he' => 'תודה על הודעתך. אחד מאנשי צוות התמיכה של sveevee יענה בהקדם האפשרי.',
            'ru' => 'Спасибо за сообщение. Сотрудник службы поддержки sveevee ответит вам как можно скорее.',
            'fr' => 'Merci pour votre message. Un membre de l’équipe d’assistance sveevee vous répondra dès que possible.',
            default => 'Thank you for your message. A member of the sveevee support team will reply as soon as possible.',
        };
    }
}
