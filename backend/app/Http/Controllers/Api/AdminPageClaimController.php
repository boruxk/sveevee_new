<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Services\AccountNotificationService;
use App\Services\ApiResponseService;
use App\Services\PageClaimService;
use App\Services\PageDeletionService;
use App\Support\AccountNotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPageClaimController extends Controller
{
    public function __construct(
        private readonly PageClaimService $claims,
        private readonly PageDeletionService $pageDeletion,
        private readonly AccountNotificationService $notifications,
    ) {}

    public function approve(Request $request, PageClaimRequest $claimRequest)
    {
        $result = DB::transaction(function () use ($request, $claimRequest): array {
            $claim = PageClaimRequest::query()
                ->with(['page', 'user'])
                ->lockForUpdate()
                ->findOrFail($claimRequest->id);

            if ($claim->status !== PageClaimRequest::STATUS_PENDING) {
                return ['error' => 'This claim request was already reviewed.', 'status' => 409];
            }

            $page = Page::query()->lockForUpdate()->findOrFail($claim->page_id);

            if (! $page->is_unclaimed) {
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

            if ($existingPages->isNotEmpty() && $page->type !== Page::TYPE_BUSINESS) {
                return ['error' => "The requester already has a {$page->type} page.", 'status' => 409];
            }

            $replacedPageName = $existingPages->first()?->name;
            $mediaPaths = [];

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
            $page->ads()->update(['user_id' => $claim->user_id]);

            $claim->forceFill([
                'status' => PageClaimRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            $competingClaims = PageClaimRequest::query()
                ->with(['user', 'conversation'])
                ->where('page_id', $page->id)
                ->where('id', '!=', $claim->id)
                ->where('status', PageClaimRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->get();

            foreach ($competingClaims as $competingClaim) {
                $competingClaim->setRelation('page', $page);
                $competingClaim->forceFill([
                    'status' => PageClaimRequest::STATUS_CANCELLED,
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_at' => now(),
                ])->save();

                $this->notifications->create($competingClaim->user, AccountNotificationType::PAGE_CLAIM_REJECTED, [
                    'page' => $this->notifications->pageSnapshot($page),
                    'claim_id' => $competingClaim->id,
                    'reason' => 'claimed_by_another',
                    'action_path' => $page->public_path,
                ]);
                $this->appendReviewMessage($competingClaim, false);
            }

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
            $this->claims->requestPayload($result['claim']),
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
            $this->claims->requestPayload($result['claim']),
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
