<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Services\ApiResponseService;
use App\Services\BusinessPageClaimConflictService;
use App\Services\PageClaimService;
use App\Services\PageDeletionService;
use App\Services\PageFormDataService;
use App\Support\AccountNotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPageClaimController extends Controller
{
    public function __construct(
        private readonly PageClaimService $claims,
        private readonly PageDeletionService $pageDeletion,
        private readonly AccountNotificationService $notifications,
        private readonly BusinessPageClaimConflictService $conflicts,
        private readonly PageFormDataService $forms,
    ) {}

    public function approve(Request $request, PageClaimRequest $claimRequest)
    {
        $result = DB::transaction(function () use ($request, $claimRequest): array {
            // Match the manual form's user -> page -> claim locking order. A new
            // requester page cannot appear between the check and the transfer.
            $requester = User::query()->lockForUpdate()->findOrFail($claimRequest->user_id);
            $page = Page::query()->lockForUpdate()->findOrFail($claimRequest->page_id);
            $claim = PageClaimRequest::query()
                ->with(['page', 'user'])
                ->lockForUpdate()
                ->findOrFail($claimRequest->id);

            if ($claim->status !== PageClaimRequest::STATUS_PENDING) {
                return ['error' => 'This claim request was already reviewed.', 'status' => 409];
            }

            $isConflict = $claim->kind === PageClaimRequest::KIND_CONFLICT;
            if ($isConflict && (! $this->conflicts->ownershipUnchanged($claim, $page)
                || $requester->banned_at !== null || ! $requester->hasAnyRole(['user', 'admin']))) {
                return ['error' => 'Ownership or requester status changed while this conflict was pending.', 'status' => 409];
            }
            if (! $isConflict && ! $page->is_unclaimed) {
                return ['error' => 'This page is already managed.', 'status' => 409];
            }

            $existingPages = Page::query()
                ->where('user_id', $claim->user_id)
                ->where('type', $page->type)
                ->where('is_unclaimed', false)
                ->whereKeyNot($page->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existingPages->isNotEmpty() && ($isConflict || $page->type !== Page::TYPE_BUSINESS)) {
                return ['error' => "The requester already has a {$page->type} page.", 'status' => 409];
            }

            $replacedPageName = $existingPages->first()?->name;
            $mediaPaths = [];
            $previousOwner = $isConflict && ! $page->is_unclaimed ? $page->user : null;

            if ($isConflict) {
                if (! is_array($claim->proposed_data) || ! is_string($claim->proposed_data['name'] ?? null)
                    || trim($claim->proposed_data['name']) === '' || ! is_array($claim->proposed_data['setup'] ?? null)) {
                    return ['error' => 'The staged business form is incomplete. Please submit it again.', 'status' => 409];
                }
                $mediaPaths = $this->forms->fill($page, $claim->proposed_data);
            }

            foreach ($existingPages as $existingPage) {
                $mediaPaths = [
                    ...$mediaPaths,
                    ...$this->pageDeletion->deleteInCurrentTransaction($existingPage),
                ];
            }

            $page->forceFill([
                'user_id' => $claim->user_id,
                'is_unclaimed' => false,
                'claimed_at' => now(),
            ])->save();
            $adFields = ['user_id' => $claim->user_id];
            if ($isConflict) {
                $adFields['city'] = data_get($page->setup, 'address.city');
                $adFields['neighborhood'] = data_get($page->setup, 'address.neighborhood');
            }
            $page->ads()->update($adFields);

            $claim->forceFill([
                'status' => PageClaimRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            $claim->setRelation('page', $page);
            $this->claims->cancelCompetingRequests($page, $request->user(), $claim);

            $this->appendReviewMessage($claim, true);
            $notificationData = [
                'page' => $this->notifications->pageSnapshot($page),
                'claim_id' => $claim->id,
                'action_path' => '/'.$page->type,
            ];

            if ($replacedPageName) {
                $notificationData['replaced_page_name'] = $replacedPageName;
            }

            $this->notifications->create(
                $claim->user,
                AccountNotificationType::PAGE_CLAIM_APPROVED,
                $notificationData,
            );
            if ($previousOwner?->hasAnyRole(['user', 'admin']) && $previousOwner->id !== $requester->id) {
                $this->notifications->create($previousOwner, AccountNotificationType::PAGE_DETACHED, [
                    'page' => $this->notifications->pageSnapshot($page), 'action_path' => '/me',
                ]);
            }

            return [
                'claim' => $claim->fresh(['page', 'user.profile', 'reviewedBy.profile']),
                'media_paths' => array_values(array_unique($mediaPaths)),
            ];
        });

        if (isset($result['error'])) {
            return ApiResponseService::error($result['error'], status: $result['status']);
        }

        $this->pageDeletion->deleteMedia($result['media_paths']);

        return ApiResponseService::success(
            $this->claims->requestPayload($result['claim'], forAdmin: true),
            'Page claim approved.'
        );
    }

    public function cancel(Request $request, PageClaimRequest $claimRequest)
    {
        $result = DB::transaction(function () use ($request, $claimRequest): array {
            $claim = PageClaimRequest::query()
                ->with(['page', 'user'])
                ->lockForUpdate()
                ->findOrFail($claimRequest->id);

            if ($claim->status !== PageClaimRequest::STATUS_PENDING) {
                return ['error' => 'This claim request was already reviewed.', 'status' => 409];
            }

            $claim->forceFill([
                'status' => PageClaimRequest::STATUS_CANCELLED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
            $this->appendReviewMessage($claim, false);
            $this->notifications->create($claim->user, AccountNotificationType::PAGE_CLAIM_REJECTED, [
                'page' => $this->notifications->pageSnapshot($claim->page),
                'claim_id' => $claim->id,
                'reason' => 'review_rejected',
                'action_path' => $claim->page->public_path,
            ]);

            return ['claim' => $claim->fresh(['page', 'user.profile', 'reviewedBy.profile'])];
        });

        if (isset($result['error'])) {
            return ApiResponseService::error($result['error'], status: $result['status']);
        }

        return ApiResponseService::success(
            $this->claims->requestPayload($result['claim'], forAdmin: true),
            'Page claim cancelled.'
        );
    }

    private function appendReviewMessage(PageClaimRequest $claim, bool $approved): void
    {
        $conversation = $claim->conversation;
        $supportAdmin = $this->claims->supportAdmin();

        if ($conversation && $supportAdmin) {
            $this->claims->appendMessage(
                $conversation,
                $supportAdmin,
                $this->claims->reviewedMarker($claim, $approved)
            );
        }
    }
}
