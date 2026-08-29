<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;

class PageClaimService
{
    public function supportAdmin(): ?User
    {
        return User::query()
            ->where('email', config('sveevee.support_admin_email'))
            ->first();
    }

    public function supportConversation(User $user, User $supportAdmin): Conversation
    {
        [$one, $two] = Conversation::pairFor($user, $supportAdmin);

        return Conversation::query()->firstOrCreate(
            ['user_one_id' => $one, 'user_two_id' => $two, 'is_support' => true],
            ['started_by_user_id' => $user->id]
        );
    }

    public function appendMessage(Conversation $conversation, User $sender, string $body): ChatMessage
    {
        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        return $message;
    }

    public function requestPayload(PageClaimRequest $claim): array
    {
        $claim->loadMissing(['page', 'user.profile', 'reviewedBy.profile']);

        return [
            'id' => $claim->id,
            'status' => $claim->status,
            'message' => $claim->message,
            'page' => $claim->page ? [
                'id' => $claim->page->id,
                'name' => $claim->page->name,
                'slug' => $claim->page->public_slug,
                'public_path' => $this->pagePath($claim->page),
                'type' => $claim->page->type,
                'is_unclaimed' => (bool) $claim->page->is_unclaimed,
            ] : null,
            'requester' => $claim->user ? [
                'id' => $claim->user->id,
                'display_name' => $claim->user->display_name,
                'email' => $claim->user->email,
            ] : null,
            'reviewed_by' => $claim->reviewedBy?->display_name,
            'reviewed_at' => $claim->reviewed_at?->toISOString(),
            'created_at' => $claim->created_at?->toISOString(),
        ];
    }

    public function createdMarker(PageClaimRequest $claim): string
    {
        return "[PAGE CLAIM REQUEST #{$claim->id}]\n"
            ."Page: {$claim->page->name}\n"
            ."Type: {$claim->page->type}\n"
            .'URL: '.rtrim((string) config('app.frontend_url'), '/').$this->pagePath($claim->page)."\n"
            ."Message: {$claim->message}";
    }

    public function reviewedMarker(PageClaimRequest $claim, bool $approved): string
    {
        $result = $approved ? 'APPROVED' : 'CANCELLED';

        return "[PAGE CLAIM {$result} #{$claim->id}]\nPage: {$claim->page->name}\nType: {$claim->page->type}";
    }

    private function pagePath(Page $page): string
    {
        return $page->public_path;
    }
}
