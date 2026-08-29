<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageChatMessage;
use App\Models\PageConversation;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class PageChatController extends Controller
{
    public function __construct(private readonly PayloadService $payloads) {}

    public function ownerIndex(Request $request, Page $page)
    {
        if ($error = $this->guardOwner($request->user(), $page)) {
            return $error;
        }

        $conversations = PageConversation::query()
            ->where('page_id', $page->id)
            ->whereNotNull('last_message_at')
            ->with(['page', 'visitor.profile', 'messages.sender.profile'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (PageConversation $conversation) => $this->payloads->pageConversation(
                $conversation,
                $request->user(),
                $this->composerState($request->user(), $conversation)
            ))
            ->values();

        return ApiResponseService::success(['conversations' => $conversations]);
    }

    public function visitorIndex(Request $request)
    {
        $user = $request->user();
        $conversations = PageConversation::query()
            ->where('visitor_id', $user->id)
            ->whereNotNull('last_message_at')
            ->whereHas('page', fn ($query) => $query->where('is_unclaimed', false))
            ->whereHas('page.user', fn ($query) => $query->whereNull('banned_at'))
            ->with(['page', 'visitor.profile', 'messages.sender.profile'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (PageConversation $conversation) => $this->payloads->pageConversation(
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

    public function visitorShow(Request $request, Page $page)
    {
        if ($error = $this->guardVisitor($request->user(), $page)) {
            return $error;
        }

        $conversation = $this->conversationFor($page, $request->user());
        $this->markRead($request->user(), $conversation);
        $this->loadConversation($conversation);

        return ApiResponseService::success($this->payloads->pageConversation(
            $conversation,
            $request->user(),
            $this->composerState($request->user(), $conversation),
            withMessages: true
        ));
    }

    public function show(Request $request, PageConversation $pageConversation)
    {
        if (! $this->isParticipant($request->user(), $pageConversation)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->markRead($request->user(), $pageConversation);
        $this->loadConversation($pageConversation);

        return ApiResponseService::success($this->payloads->pageConversation(
            $pageConversation,
            $request->user(),
            $this->composerState($request->user(), $pageConversation),
            withMessages: true
        ));
    }

    public function sendToPage(Request $request, Page $page)
    {
        if ($error = $this->guardVisitor($request->user(), $page)) {
            return $error;
        }

        return $this->sendIntoConversation($request, $this->conversationFor($page, $request->user()));
    }

    public function send(Request $request, PageConversation $pageConversation)
    {
        if (! $this->isParticipant($request->user(), $pageConversation)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        return $this->sendIntoConversation($request, $pageConversation);
    }

    public function markAsRead(Request $request, PageConversation $pageConversation)
    {
        if (! $this->isParticipant($request->user(), $pageConversation)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->markRead($request->user(), $pageConversation);

        return ApiResponseService::success(null);
    }

    private function sendIntoConversation(Request $request, PageConversation $conversation)
    {
        $conversation->loadMissing(['page.user', 'messages']);
        $state = $this->composerState($request->user(), $conversation);

        if (! $state['can_send']) {
            return ApiResponseService::error($state['message'], ['reason' => $state['reason']], 409);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000', new CleanContent],
        ]);

        $senderAsPage = $conversation->page->user_id === $request->user()->id;
        $message = PageChatMessage::query()->create([
            'page_conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'sender_as_page' => $senderAsPage,
            'body' => trim($data['body']),
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();
        $this->loadConversation($conversation);

        return ApiResponseService::success($this->payloads->pageConversation(
            $conversation,
            $request->user(),
            $this->composerState($request->user(), $conversation),
            withMessages: true
        ), 'Message sent.', 201);
    }

    private function conversationFor(Page $page, User $visitor): PageConversation
    {
        return PageConversation::query()->firstOrCreate([
            'page_id' => $page->id,
            'visitor_id' => $visitor->id,
        ]);
    }

    private function composerState(User $user, PageConversation $conversation): array
    {
        $conversation->loadMissing(['page', 'messages']);

        if ($conversation->page->is_unclaimed) {
            return [
                'can_send' => false,
                'reason' => 'page_unclaimed',
                'message' => 'Chat becomes available after this page is claimed.',
            ];
        }

        if ($conversation->page->user_id === $user->id) {
            return ['can_send' => true, 'reason' => null, 'message' => null];
        }

        $visitorMessages = $conversation->messages->where('sender_as_page', false)->count();
        $pageMessages = $conversation->messages->where('sender_as_page', true)->count();

        if ($visitorMessages > 0 && $pageMessages === 0) {
            return [
                'can_send' => false,
                'reason' => 'page_pending_reply',
                'message' => 'You can write again after this page replies to your first message.',
            ];
        }

        return ['can_send' => true, 'reason' => null, 'message' => null];
    }

    private function guardOwner(User $user, Page $page)
    {
        $page->loadMissing('user');

        if ($page->is_unclaimed) {
            return ApiResponseService::error('Generated pages cannot use chat before they are claimed.', status: 409);
        }

        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        return $page->user_id === $user->id
            ? null
            : ApiResponseService::error('This action is unauthorized.', status: 403);
    }

    private function guardVisitor(User $user, Page $page)
    {
        $page->loadMissing('user');

        if ($page->is_unclaimed) {
            return ApiResponseService::error('Chat becomes available after this page is claimed.', status: 409);
        }

        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        return $page->user_id !== $user->id
            ? null
            : ApiResponseService::error('Open the page chat inbox to reply as this page.', status: 422);
    }

    private function isParticipant(User $user, PageConversation $conversation): bool
    {
        $conversation->loadMissing('page');

        return $conversation->visitor_id === $user->id || $conversation->page->user_id === $user->id;
    }

    private function markRead(User $user, PageConversation $conversation): void
    {
        PageChatMessage::query()
            ->where('page_conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function loadConversation(PageConversation $conversation): void
    {
        $conversation->load(['page', 'visitor.profile', 'messages.sender.profile']);
    }
}
