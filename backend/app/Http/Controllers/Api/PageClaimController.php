<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PageClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageClaimController extends Controller
{
    public function __construct(private readonly PageClaimService $claims) {}

    public function store(Request $request, Page $page)
    {
        $user = $request->user();

        if (! $user->hasRole('user')) {
            return ApiResponseService::error('This account cannot claim a page.', status: 403);
        }

        if (! $page->is_unclaimed) {
            return ApiResponseService::error('This page is already managed.', status: 409);
        }

        if (Page::query()
            ->where('user_id', $user->id)
            ->where('type', Page::TYPE_BUSINESS)
            ->where('is_unclaimed', false)
            ->exists()) {
            return ApiResponseService::error('Your account already has a business page.', status: 409);
        }

        $request->merge(['message' => trim((string) $request->input('message'))]);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000', new CleanContent],
        ]);
        $supportAdmin = $this->claims->supportAdmin();

        if (! $supportAdmin || $supportAdmin->banned_at) {
            return ApiResponseService::error('Support chat is not available right now.', status: 503);
        }

        $result = DB::transaction(function () use ($page, $user, $supportAdmin, $data): array {
            $lockedPage = Page::query()->lockForUpdate()->findOrFail($page->id);

            if (! $lockedPage->is_unclaimed) {
                return ['error' => 'This page is already managed.', 'status' => 409];
            }

            $pending = PageClaimRequest::query()
                ->where('page_id', $lockedPage->id)
                ->where('user_id', $user->id)
                ->where('status', PageClaimRequest::STATUS_PENDING)
                ->first();

            if ($pending) {
                return ['error' => 'A claim request is already pending.', 'status' => 409];
            }

            $conversation = $this->claims->supportConversation($user, $supportAdmin);
            $claim = PageClaimRequest::query()->create([
                'page_id' => $lockedPage->id,
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'status' => PageClaimRequest::STATUS_PENDING,
                'message' => $data['message'],
            ]);
            $claim->setRelation('page', $lockedPage);
            $this->claims->appendMessage($conversation, $user, $this->claims->createdMarker($claim));

            return ['claim' => $claim];
        });

        if (isset($result['error'])) {
            return ApiResponseService::error($result['error'], status: $result['status']);
        }

        return ApiResponseService::success(
            $this->claims->requestPayload($result['claim']),
            'Claim request sent.',
            201
        );
    }
}
