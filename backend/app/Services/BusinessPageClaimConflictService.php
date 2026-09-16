<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Support\AccountNotificationType;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessPageClaimConflictService
{
    public const PROPOSED_FIELDS = [
        'name', 'public_description', 'contact_email', 'phone', 'address', 'category_key',
        'palette_key', 'setup', 'logo_path', 'logo_original_name', 'banner_path', 'banner_original_name',
    ];

    public function __construct(
        private readonly PageClaimService $claims,
        private readonly AccountNotificationService $notifications,
    ) {}

    /** The caller has validated/stored the form and holds the requester's row lock. */
    public function submit(User $user, Page $page, array $proposedData, array $matchedOn, ?string $groupId = null): PageClaimRequest
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A business conflict must be staged inside the page transaction.');
        }
        if ($groupId !== null && ! Str::isUuid($groupId)) {
            throw new \InvalidArgumentException('A conflict group must be a UUID.');
        }
        $supportAdmin = $this->claims->supportAdmin();
        if ($supportAdmin === null || $supportAdmin->banned_at !== null) {
            $this->fail('Support chat is not available right now.', 503);
        }
        $page = Page::query()->with('user')->lockForUpdate()->findOrFail($page->id);
        if ($page->type !== Page::TYPE_BUSINESS || $page->user_id === $user->id
            || (! $page->is_unclaimed && $page->user === null) || $user->banned_at !== null
            || ! $user->hasAnyRole(['user', 'admin'])) {
            $this->fail('The matching business ownership has changed. Please retry.');
        }
        $proposedData = array_intersect_key($proposedData, array_flip(self::PROPOSED_FIELDS));
        if (! is_string($proposedData['name'] ?? null) || trim($proposedData['name']) === ''
            || ! is_array($proposedData['setup'] ?? null)) {
            throw new \InvalidArgumentException('Conflict data must contain the validated business name and setup.');
        }
        $matchedOn = array_values(array_unique(array_filter($matchedOn, 'is_string')));
        $pending = PageClaimRequest::query()->where('page_id', $page->id)->where('user_id', $user->id)
            ->where('kind', PageClaimRequest::KIND_CONFLICT)->where('status', PageClaimRequest::STATUS_PENDING)
            ->lockForUpdate()->first();
        if ($pending !== null) {
            if (! $this->ownershipUnchanged($pending, $page)) {
                $this->fail('The matching business ownership changed while this request was pending.');
            }
            foreach (['logo', 'banner'] as $image) {
                if (! array_key_exists($image.'_path', $proposedData)) {
                    foreach ([$image.'_path', $image.'_original_name'] as $field) {
                        if (array_key_exists($field, $pending->proposed_data ?? [])) {
                            $proposedData[$field] = $pending->proposed_data[$field];
                        }
                    }
                }
            }
            $changed = $pending->proposed_data !== $proposedData || $pending->matched_on !== $matchedOn;
            $pending->forceFill(['proposed_data' => $proposedData, 'matched_on' => $matchedOn,
                'conflict_group_id' => $groupId])->save();
            $pending->setRelation('page', $page);
            if ($changed && $pending->conversation !== null) {
                $this->claims->appendMessage($pending->conversation, $user,
                    "[CLAIM CONFLICT UPDATED #{$pending->id}]\n".$this->claims->createdMarker($pending));
            }

            return $pending;
        }
        $conversation = $this->claims->supportConversation($user, $supportAdmin);
        $claim = PageClaimRequest::create([
            'page_id' => $page->id, 'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'kind' => PageClaimRequest::KIND_CONFLICT, 'status' => PageClaimRequest::STATUS_PENDING,
            'message' => 'CLAIM CONFLICT: a submitted business matches an existing page and requires review.',
            'replace_existing' => false, 'proposed_data' => $proposedData, 'matched_on' => $matchedOn,
            'owner_at_request_user_id' => $page->user_id, 'owner_at_request_claimed_at' => $page->claimed_at,
            'owner_at_request_is_unclaimed' => (bool) $page->is_unclaimed,
            'conflict_group_id' => $groupId,
        ]);
        $claim->setRelation('page', $page);
        $this->claims->appendMessage($conversation, $user, $this->claims->createdMarker($claim));
        $this->notifications->createForAdmins(AccountNotificationType::PAGE_CLAIM_SUBMITTED, [
            'page' => $this->notifications->pageSnapshot($page), 'claim_id' => $claim->id,
            'kind' => PageClaimRequest::KIND_CONFLICT, 'requester_name' => $user->display_name,
            'action_path' => '/admin?tab=communication',
        ]);

        return $claim;
    }

    public function ownershipUnchanged(PageClaimRequest $claim, Page $page): bool
    {
        return $page->type === Page::TYPE_BUSINESS
            && ($page->is_unclaimed || $page->user_id !== null)
            && (bool) $page->is_unclaimed === (bool) $claim->owner_at_request_is_unclaimed
            && $page->user_id === $claim->owner_at_request_user_id
            && $page->claimed_at?->toDateTimeString() === $claim->owner_at_request_claimed_at?->toDateTimeString();
    }

    /** Superseded proposals remain in the audit history; shared staged files stay intact. */
    public function cancelObsoleteSubmissions(User $user, array $keptPageIds): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Superseded conflicts must be resolved inside the page transaction.');
        }
        $obsolete = PageClaimRequest::query()->with(['page', 'conversation'])
            ->where('user_id', $user->id)->where('kind', PageClaimRequest::KIND_CONFLICT)
            ->where('status', PageClaimRequest::STATUS_PENDING)->whereNotIn('page_id', $keptPageIds)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($obsolete as $claim) {
            $claim->forceFill(['status' => PageClaimRequest::STATUS_CANCELLED,
                'reviewed_by_user_id' => $user->id, 'reviewed_at' => now()])->save();
            if ($claim->conversation !== null) {
                $this->claims->appendMessage($claim->conversation, $user,
                    "[CLAIM CONFLICT SUPERSEDED #{$claim->id}]\nA newer business submission no longer matches this page.");
            }
        }
    }

    private function fail(string $message, int $status = 409): never
    {
        throw new HttpResponseException(ApiResponseService::error($message, status: $status));
    }
}
