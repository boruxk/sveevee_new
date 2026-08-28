<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Services\ApiResponseService;
use App\Services\PageClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPageClaimController extends Controller
{
    public function __construct(private readonly PageClaimService $claims) {}

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

            if (Page::query()
                ->where('user_id', $claim->user_id)
                ->where('type', Page::TYPE_BUSINESS)
                ->where('is_unclaimed', false)
                ->exists()) {
                return ['error' => 'The requester already has a business page.', 'status' => 409];
            }

            $page->forceFill([
                'user_id' => $claim->user_id,
                'is_unclaimed' => false,
                'claimed_at' => now(),
            ])->save();

            $claim->forceFill([
                'status' => PageClaimRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            PageClaimRequest::query()
                ->where('page_id', $page->id)
                ->where('id', '!=', $claim->id)
                ->where('status', PageClaimRequest::STATUS_PENDING)
                ->update([
                    'status' => PageClaimRequest::STATUS_CANCELLED,
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

            $this->appendReviewMessage($claim, true);

            return ['claim' => $claim->fresh(['page', 'user.profile', 'reviewedBy.profile'])];
        });

        if (isset($result['error'])) {
            return ApiResponseService::error($result['error'], status: $result['status']);
        }

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
