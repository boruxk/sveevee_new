<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\GuestSupportConversation;
use App\Models\GuestSupportMessage;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\GuestSupportService;
use App\Services\PageClaimService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class AdminSupportController extends Controller
{
    public function __construct(
        private readonly PayloadService $payloads,
        private readonly GuestSupportService $guestSupport,
        private readonly PageClaimService $pageClaims,
    ) {}

    public function index(Request $request)
    {
        $admin = $request->user();

        $accountConversations = Conversation::query()
            ->forParticipant($admin)
            ->where('is_support', true)
            ->whereNotNull('last_message_at')
            ->with([
                'userOne.profile',
                'userTwo.profile',
                'messages.sender.profile',
                'claimRequests.page',
                'claimRequests.user.profile',
                'claimRequests.reviewedBy.profile',
            ])
            ->get()
            ->map(fn (Conversation $conversation): array => $this->accountPayload($conversation, $admin));

        $guestConversations = GuestSupportConversation::query()
            ->whereNull('claimed_at')
            ->whereNotNull('last_message_at')
            ->with(['messages.sender.profile'])
            ->get()
            ->map(fn (GuestSupportConversation $conversation): array => $this->guestSupport->payload(
                $conversation,
                forAdmin: true
            ));

        $conversations = $accountConversations
            ->concat($guestConversations)
            ->sortByDesc('last_message_at')
            ->values();

        return ApiResponseService::success([
            'conversations' => $conversations,
            'unread_count' => $conversations->sum('unread_count'),
        ]);
    }

    public function show(Request $request, string $source, int $id)
    {
        if ($source === 'account') {
            $conversation = $this->accountConversation($request->user(), $id);

            if (! $conversation) {
                return ApiResponseService::error('Resource not found.', status: 404);
            }

            ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_id', '!=', $request->user()->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $conversation->load([
                'userOne.profile',
                'userTwo.profile',
                'messages.sender.profile',
                'claimRequests.page',
                'claimRequests.user.profile',
                'claimRequests.reviewedBy.profile',
            ]);

            return ApiResponseService::success($this->accountPayload(
                $conversation,
                $request->user(),
                withMessages: true
            ));
        }

        if ($source !== 'guest') {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $conversation = GuestSupportConversation::query()
            ->whereNull('claimed_at')
            ->find($id);

        if (! $conversation) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $this->guestSupport->markReadByAdmin($conversation);
        $conversation->load(['messages.sender.profile']);

        return ApiResponseService::success($this->guestSupport->payload(
            $conversation,
            withMessages: true,
            forAdmin: true
        ));
    }

    public function send(Request $request, string $source, int $id)
    {
        $request->merge(['body' => trim((string) $request->input('body'))]);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000', new CleanContent],
        ]);

        if ($source === 'account') {
            $conversation = $this->accountConversation($request->user(), $id);

            if (! $conversation) {
                return ApiResponseService::error('Resource not found.', status: 404);
            }

            $message = ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $request->user()->id,
                'body' => $data['body'],
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            $conversation->load([
                'userOne.profile',
                'userTwo.profile',
                'messages.sender.profile',
                'claimRequests.page',
                'claimRequests.user.profile',
                'claimRequests.reviewedBy.profile',
            ]);

            return ApiResponseService::success($this->accountPayload(
                $conversation,
                $request->user(),
                withMessages: true
            ), 'Message sent.', 201);
        }

        if ($source !== 'guest') {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $conversation = GuestSupportConversation::query()
            ->whereNull('claimed_at')
            ->find($id);

        if (! $conversation) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $message = $conversation->messages()->create([
            'sender_type' => GuestSupportMessage::SENDER_ADMIN,
            'sender_user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();
        $conversation->load(['messages.sender.profile']);

        return ApiResponseService::success($this->guestSupport->payload(
            $conversation,
            withMessages: true,
            forAdmin: true
        ), 'Message sent.', 201);
    }

    private function accountConversation(User $admin, int $id): ?Conversation
    {
        return Conversation::query()
            ->forParticipant($admin)
            ->where('is_support', true)
            ->find($id);
    }

    private function accountPayload(Conversation $conversation, User $admin, bool $withMessages = false): array
    {
        $payload = $this->payloads->conversation(
            $conversation,
            $admin,
            ['can_send' => true, 'reason' => null, 'message' => null],
            $withMessages
        );

        $claimRequests = $conversation->claimRequests
            ->map(fn ($claim): array => $this->pageClaims->requestPayload($claim))
            ->values()
            ->all();

        return [
            ...$payload,
            'support_key' => "account:{$conversation->id}",
            'source' => 'account',
            'participant' => $payload['other_user'],
            'is_guest' => false,
            'claim_requests' => $claimRequests,
            'pending_claim_count' => collect($claimRequests)
                ->where('status', 'pending')
                ->count(),
        ];
    }
}
