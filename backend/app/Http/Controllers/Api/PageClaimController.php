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

        $request->merge(['message' => trim((string) $request->input('message'))]);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000', new CleanContent],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);
        $replacementConfirmed = $request->boolean('replace_existing');
        $supportAdmin = $this->claims->supportAdmin();

        if (! $supportAdmin || $supportAdmin->banned_at) {
            return ApiResponseService::error('Support chat is not available right now.', status: 503);
        }

        $result = DB::transaction(function () use ($page, $user, $supportAdmin, $data, $replacementConfirmed): array {
            $lockedPage = Page::query()->lockForUpdate()->findOrFail($page->id);

            if (! $lockedPage->is_unclaimed) {
                return ['error' => 'This page is already managed.', 'status' => 409];
            }

            $existingPage = Page::query()
                ->where('user_id', $user->id)
                ->where('type', $lockedPage->type)
                ->where('is_unclaimed', false)
                ->whereKeyNot($lockedPage->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existingPage && $lockedPage->type !== Page::TYPE_BUSINESS) {
                return ['error' => "Your account already has a {$lockedPage->type} page.", 'status' => 409];
            }

            if ($existingPage && ! $replacementConfirmed) {
                return [
                    'error' => 'Confirm that your existing business page may be replaced.',
                    'status' => 409,
                    'data' => [
                        'requires_replacement_confirmation' => true,
                        'existing_page' => [
                            'id' => $existingPage->id,
                            'name' => $existingPage->name,
                        ],
                    ],
                ];
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
                'replace_existing' => (bool) $existingPage,
            ]);
            $claim->setRelation('page', $lockedPage);
            $this->claims->appendMessage($conversation, $user, $this->claims->createdMarker($claim));

            return ['claim' => $claim];
        });

        if (isset($result['error'])) {
            return ApiResponseService::error(
                $result['error'],
                status: $result['status'],
                data: $result['data'] ?? null
            );
        }

        return ApiResponseService::success(
            $this->claims->requestPayload($result['claim']),
            'Claim request sent.',
            201
        );
    }
}
