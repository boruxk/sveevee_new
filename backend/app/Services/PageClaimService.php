<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Support\AccountNotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    public function requestPayload(PageClaimRequest $claim, bool $forAdmin = false): array
    {
        $claim->loadMissing(['page.user.profile', 'user.profile', 'reviewedBy.profile']);
        $conflict = $claim->kind === PageClaimRequest::KIND_CONFLICT;
        $proposed = $forAdmin && $conflict && is_array($claim->proposed_data) ? $claim->proposed_data : null;
        if ($proposed !== null) {
            foreach (['logo', 'banner'] as $image) {
                if (! array_key_exists($image.'_path', $proposed)) {
                    continue;
                }
                $path = $proposed[$image.'_path'] ?? null;
                $proposed[$image.'_url'] = is_string($path) && $path !== '' ? url(Storage::url($path)) : null;
            }
        }

        return [
            'id' => $claim->id,
            'status' => $claim->status,
            'message' => $claim->message,
            'replace_existing' => (bool) $claim->replace_existing,
            'kind' => $claim->kind ?? PageClaimRequest::KIND_OWNERSHIP,
            'proposed_data' => $proposed,
            'matched_on' => $conflict ? ($claim->matched_on ?? []) : [],
            'conflict_group_id' => $claim->conflict_group_id,
            'owner_at_request_user_id' => $forAdmin && $conflict ? $claim->owner_at_request_user_id : null,
            'current_owner' => $forAdmin && $conflict && ! $claim->page?->is_unclaimed && $claim->page?->user ? [
                'id' => $claim->page->user->id,
                'display_name' => $claim->page->user->display_name,
                ...($forAdmin ? ['email' => $claim->page->user->email] : []),
            ] : null,
            'page' => $claim->page ? [
                'id' => $claim->page->id,
                'name' => $claim->page->name,
                'slug' => $claim->page->public_slug,
                'public_path' => $this->pagePath($claim->page),
                'type' => $claim->page->type,
                'is_unclaimed' => (bool) $claim->page->is_unclaimed,
                ...($forAdmin && $conflict ? [
                    'user_id' => $claim->page->user_id,
                    'public_description' => $claim->page->public_description,
                    'contact_email' => $claim->page->contact_email,
                    'phone' => $claim->page->phone,
                    'address' => $claim->page->address,
                    'category_key' => $claim->page->category_key,
                    'palette_key' => $claim->page->palette_key,
                    'setup' => $claim->page->setup,
                    'logo_url' => $claim->page->logo_url,
                    'banner_url' => $claim->page->banner_url,
                ] : []),
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
        if ($claim->kind === PageClaimRequest::KIND_CONFLICT) {
            return "[CLAIM CONFLICT #{$claim->id}]\nPage: {$claim->page->name}\n"
                .'URL: '.rtrim((string) config('app.frontend_url'), '/').$this->pagePath($claim->page)."\n"
                .'Matched fields: '.implode(', ', $claim->matched_on ?? [])."\n"
                .'Proposed business: '.($claim->proposed_data['name'] ?? '')."\n"
                .'Review the matching page, its ownership and the submitted changes before approving.';
        }

        return "[PAGE CLAIM REQUEST #{$claim->id}]\n"
            ."Page: {$claim->page->name}\n"
            ."Type: {$claim->page->type}\n"
            .'URL: '.rtrim((string) config('app.frontend_url'), '/').$this->pagePath($claim->page)."\n"
            .'Replace existing business page: '.($claim->replace_existing ? 'yes' : 'no')."\n"
            ."Message: {$claim->message}";
    }

    public function reviewedMarker(PageClaimRequest $claim, bool $approved): string
    {
        $result = $approved ? 'APPROVED' : 'CANCELLED';

        $kind = $claim->kind === PageClaimRequest::KIND_CONFLICT ? 'CLAIM CONFLICT' : 'PAGE CLAIM';

        return "[{$kind} {$result} #{$claim->id}]\nPage: {$claim->page->name}\nType: {$claim->page->type}";
    }

    private function pagePath(Page $page): string
    {
        return $page->public_path;
    }

    public function cancelCompetingRequests(Page $page, User $actor, ?PageClaimRequest $approvedClaim = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Competing claims must be resolved inside the ownership transaction.');
        }
        if ($approvedClaim === null) {
            $winningClaim = PageClaimRequest::query()->with(['user', 'conversation'])
                ->where('page_id', $page->id)->where('user_id', $page->user_id)
                ->where('status', PageClaimRequest::STATUS_PENDING)->orderBy('id')->lockForUpdate()->first();
            if ($winningClaim !== null) {
                $winningClaim->setRelation('page', $page);
                $winningClaim->forceFill(['status' => PageClaimRequest::STATUS_APPROVED,
                    'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()])->save();
                $notifications = app(AccountNotificationService::class);
                $notifications->create($winningClaim->user, AccountNotificationType::PAGE_CLAIM_APPROVED, [
                    'page' => $notifications->pageSnapshot($page), 'claim_id' => $winningClaim->id,
                    'kind' => $winningClaim->kind, 'action_path' => '/'.$page->type,
                ]);
                if ($winningClaim->conversation !== null && ($supportAdmin = $this->supportAdmin()) !== null) {
                    $this->appendMessage($winningClaim->conversation, $supportAdmin, $this->reviewedMarker($winningClaim, true));
                }
                $approvedClaim = $winningClaim;
            }
        }
        $claims = PageClaimRequest::query()->with(['page', 'user', 'conversation'])
            ->where('status', PageClaimRequest::STATUS_PENDING)
            ->when($approvedClaim, fn ($query) => $query->whereKeyNot($approvedClaim->id))
            ->where(function ($query) use ($page, $approvedClaim): void {
                $query->where('page_id', $page->id);
                if ($approvedClaim?->conflict_group_id !== null) {
                    $query->orWhere(fn ($group) => $group->where('kind', PageClaimRequest::KIND_CONFLICT)
                        ->where('user_id', $approvedClaim->user_id)->where('conflict_group_id', $approvedClaim->conflict_group_id));
                }
            })->orderBy('id')->lockForUpdate()->get();
        $notifications = app(AccountNotificationService::class);
        $supportAdmin = $this->supportAdmin();
        foreach ($claims as $claim) {
            if ($claim->page_id === $page->id) {
                $claim->setRelation('page', $page);
            }
            $claim->forceFill(['status' => PageClaimRequest::STATUS_CANCELLED,
                'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()])->save();
            if ($claim->user !== null && $claim->page !== null) {
                $notifications->create($claim->user, AccountNotificationType::PAGE_CLAIM_REJECTED, [
                    'page' => $notifications->pageSnapshot($claim->page), 'claim_id' => $claim->id,
                    'kind' => $claim->kind, 'reason' => 'claimed_by_another', 'action_path' => $claim->page->public_path,
                ]);
            }
            if ($claim->conversation !== null && $claim->page !== null && $supportAdmin !== null) {
                $this->appendMessage($claim->conversation, $supportAdmin, $this->reviewedMarker($claim, false));
            }
        }
    }
}
