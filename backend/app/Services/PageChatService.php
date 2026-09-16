<?php

namespace App\Services;

use App\Models\PageConversation;
use App\Models\User;

class PageChatService
{
    public function composerState(?User $user, PageConversation $conversation): array
    {
        $conversation->loadMissing(['page.user', 'messages']);

        if ($conversation->page->is_unclaimed) {
            return [
                'can_send' => false,
                'reason' => 'page_unclaimed',
                'message' => 'Chat becomes available after this page is claimed.',
            ];
        }

        if (! $conversation->page->user || $conversation->page->user->banned_at || $user?->banned_at) {
            return ['can_send' => false, 'reason' => 'chat_unavailable', 'message' => 'This chat is unavailable.'];
        }

        if ($user && $conversation->page->user_id === $user->id) {
            return ['can_send' => true, 'reason' => null, 'message' => null];
        }

        $visitorMessages = $conversation->messages->where('sender_as_page', false)->count();
        $pageMessages = $conversation->messages->where('sender_as_page', true)->count();

        if ($visitorMessages > 0 && $pageMessages === 0) {
            return [
                'can_send' => false,
                'reason' => 'page_pending_reply',
                'message' => 'You can write again after this page replies to your first message.',
            ];
        }

        return ['can_send' => true, 'reason' => null, 'message' => null];
    }
}
