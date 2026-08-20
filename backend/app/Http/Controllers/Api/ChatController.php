<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChatController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->forParticipant($user)
            ->when($user->hasRole('admin'), fn ($query) => $query->where('is_support', false))
            ->whereNotNull('last_message_at')
            ->with(['userOne.profile', 'userTwo.profile', 'messages.sender.profile'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Conversation $conversation) => $this->payloads->conversation(
                $conversation,
                $user,
                $this->composerState($user, $conversation)
            ))
            ->values();

        return ApiResponseService::success([
            'conversations' => $conversations,
            'unread_count' => $this->payloads->unreadMessageCount($user),
        ]);
    }

    public function adminSupportIndex(Request $request)
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->forParticipant($user)
            ->where('is_support', true)
            ->whereNotNull('last_message_at')
            ->with(['userOne.profile', 'userTwo.profile', 'messages.sender.profile'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Conversation $conversation) => $this->payloads->conversation(
                $conversation,
                $user,
                $this->composerState($user, $conversation)
            ))
            ->values();

        return ApiResponseService::success([
            'conversations' => $conversations,
            'unread_count' => $this->payloads->unreadMessageCount($user),
        ]);
    }

    public function start(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return ApiResponseService::error('You cannot chat with yourself.', status: 422);
        }

        if ($user->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $conversation = $this->conversationFor($request->user(), $user);
        $conversation->load(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);

        return ApiResponseService::success($this->payloads->conversation(
            $conversation,
            $request->user(),
            $this->composerState($request->user(), $conversation),
            withMessages: true
        ));
    }

    public function support(Request $request)
    {
        $supportAdmin = $this->supportAdmin();

        if (! $supportAdmin || $supportAdmin->banned_at) {
            return ApiResponseService::error('Support chat is not available right now.', status: 503);
        }

        if ($request->user()->id === $supportAdmin->id) {
            return ApiResponseService::error('You cannot chat with yourself.', status: 422);
        }

        $conversation = $this->conversationFor($request->user(), $supportAdmin, isSupport: true);
        $this->markRead($request->user(), $conversation);
        $conversation->load(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);

        return ApiResponseService::success($this->payloads->conversation(
            $conversation,
            $request->user(),
            $this->composerState($request->user(), $conversation),
            withMessages: true
        ));
    }

    public function show(Request $request, Conversation $conversation)
    {
        if (! $this->isParticipant($request->user(), $conversation)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->markRead($request->user(), $conversation);
        $conversation->load(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);

        return ApiResponseService::success($this->payloads->conversation(
            $conversation,
            $request->user(),
            $this->composerState($request->user(), $conversation),
            withMessages: true
        ));
    }

    public function send(Request $request, Conversation $conversation)
    {
        if (! $this->isParticipant($request->user(), $conversation)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        return $this->sendIntoConversation($request, $conversation, enforceLimits: true);
    }

    public function sendToUser(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return ApiResponseService::error('You cannot chat with yourself.', status: 422);
        }

        if ($user->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $conversation = $this->conversationFor($request->user(), $user);

        return $this->sendIntoConversation($request, $conversation, enforceLimits: true);
    }

    public function sendSupport(Request $request)
    {
        $supportAdmin = $this->supportAdmin();

        if (! $supportAdmin || $supportAdmin->banned_at) {
            return ApiResponseService::error('Support chat is not available right now.', status: 503);
        }

        if ($request->user()->id === $supportAdmin->id) {
            return ApiResponseService::error('You cannot chat with yourself.', status: 422);
        }

        $conversation = $this->conversationFor($request->user(), $supportAdmin, isSupport: true);

        return $this->sendIntoConversation($request, $conversation, enforceLimits: false);
    }

    public function markAsRead(Request $request, Conversation $conversation)
    {
        if (! $this->isParticipant($request->user(), $conversation)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->markRead($request->user(), $conversation);

        return ApiResponseService::success([
            'unread_count' => $this->payloads->unreadMessageCount($request->user()),
        ]);
    }

    public function adminSend(Request $request, User $user)
    {
        if (! $request->user()->hasRole('admin')) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $conversation = $this->conversationFor($request->user(), $user);

        return $this->sendIntoConversation($request, $conversation, enforceLimits: false);
    }

    private function sendIntoConversation(Request $request, Conversation $conversation, bool $enforceLimits)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000', new CleanContent()],
        ]);

        if ($enforceLimits) {
            $state = $this->composerState($request->user(), $conversation);

            if (! $state['can_send']) {
                return ApiResponseService::error($state['message'], ['reason' => $state['reason']], $state['reason'] === 'daily_limit' ? 429 : 409);
            }
        }

        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();
        $conversation->load(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);

        return ApiResponseService::success($this->payloads->conversation(
            $conversation,
            $request->user(),
            $this->composerState($request->user(), $conversation),
            withMessages: true
        ), 'Message sent.', 201);
    }

    private function composerState(User $user, Conversation $conversation): array
    {
        $conversation->loadMissing('messages');

        if ($this->isSupportConversation($conversation)) {
            return [
                'can_send' => true,
                'reason' => null,
                'message' => null,
            ];
        }

        $ownMessages = $conversation->messages->where('sender_id', $user->id)->count();
        $otherMessages = $conversation->messages->where('sender_id', '!=', $user->id)->count();

        if ($ownMessages > 0 && $otherMessages === 0) {
            return [
                'can_send' => false,
                'reason' => 'pending_reply',
                'message' => 'You can write again after this person replies to your first message.',
            ];
        }

        if ($ownMessages === 0 && $otherMessages === 0 && $this->newRecipientsToday($user) >= 10) {
            return [
                'can_send' => false,
                'reason' => 'daily_limit',
                'message' => 'You can contact only 10 new users per day.',
            ];
        }

        return [
            'can_send' => true,
            'reason' => null,
            'message' => null,
        ];
    }

    private function newRecipientsToday(User $user): int
    {
        $since = Carbon::today();
        $messages = ChatMessage::query()
            ->with('conversation')
            ->where('sender_id', $user->id)
            ->where('created_at', '>=', $since)
            ->oldest()
            ->get();

        return $messages
            ->filter(function (ChatMessage $message) use ($since): bool {
                $firstMessage = $message->conversation?->messages()->oldest()->first();

                return ! $message->conversation?->is_support
                    && $firstMessage?->id === $message->id
                    && $firstMessage->created_at >= $since;
            })
            ->map(function (ChatMessage $message) use ($user): ?int {
                $conversation = $message->conversation;

                if (! $conversation) {
                    return null;
                }

                return $conversation->user_one_id === $user->id
                    ? $conversation->user_two_id
                    : $conversation->user_one_id;
            })
            ->filter()
            ->unique()
            ->count();
    }

    private function conversationFor(User $current, User $other, bool $isSupport = false): Conversation
    {
        [$one, $two] = Conversation::pairFor($current, $other);

        return Conversation::query()->firstOrCreate(
            ['user_one_id' => $one, 'user_two_id' => $two, 'is_support' => $isSupport],
            ['started_by_user_id' => $current->id]
        );
    }

    private function isParticipant(User $user, Conversation $conversation): bool
    {
        return $conversation->user_one_id === $user->id || $conversation->user_two_id === $user->id;
    }

    private function markRead(User $user, Conversation $conversation): void
    {
        ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function isSupportConversation(Conversation $conversation): bool
    {
        $supportAdmin = $this->supportAdmin();

        return (bool) $conversation->is_support
            && $supportAdmin
            && in_array($supportAdmin->id, [$conversation->user_one_id, $conversation->user_two_id], true);
    }

    private function supportAdmin(): ?User
    {
        return User::query()
            ->where('email', config('sveevee.support_admin_email'))
            ->first();
    }
}
