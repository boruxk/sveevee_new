<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageChatMessage;
use App\Models\PageConversation;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PageChatService;
use App\Services\PayloadService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GuestPageChatController extends Controller
{
    public const TOKEN_HEADER = 'X-Guest-Page-Chat-Token';

    public function __construct(
        private readonly PayloadService $payloads,
        private readonly PageChatService $chats,
    ) {}

    public function store(Request $request, Page $page): JsonResponse
    {
        $data = $this->messageData($request);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return DB::transaction(function () use ($page, $data, $token): JsonResponse {
            $page = $this->lockAvailablePage($page);
            $conversation = PageConversation::query()->create([
                'page_id' => $page->id,
                'visitor_id' => null,
                'guest_token_hash' => hash('sha256', $token),
            ]);
            $this->appendGuestMessage($conversation, $data['body']);

            return ApiResponseService::success([
                'token' => $token,
                'conversation' => $this->payload($conversation),
            ], 'Conversation started.', 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $request, Page $page): JsonResponse
    {
        return DB::transaction(function () use ($request, $page): JsonResponse {
            $page = $this->lockAvailablePage($page);
            $conversation = $this->fromToken($request, $page);
            $conversation->messages()->where('sender_as_page', true)->whereNull('read_at')
                ->update(['read_at' => now()]);

            return ApiResponseService::success($this->payload($conversation))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function send(Request $request, Page $page): JsonResponse
    {
        return DB::transaction(function () use ($request, $page): JsonResponse {
            $page = $this->lockAvailablePage($page);
            $conversation = $this->fromToken($request, $page);
            $state = $this->chats->composerState(null, $conversation);

            if (! $state['can_send']) {
                $this->fail($state['message'], 409, ['reason' => $state['reason']]);
            }

            $data = $this->messageData($request);
            $this->appendGuestMessage($conversation, $data['body']);

            return ApiResponseService::success($this->payload($conversation), 'Message sent.', 201)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function claim(Request $request, Page $page): JsonResponse
    {
        $user = $request->user();

        if ($user->banned_at) {
            $this->fail('This action is unauthorized.', 403);
        }

        return DB::transaction(function () use ($request, $page, $user): JsonResponse {
            // All page-chat writes take this lock first, including normal account sends.
            $page = $this->lockAvailablePage($page);

            if ($page->user_id === $user->id) {
                $this->fail('The business owner cannot claim a visitor conversation.', 422);
            }

            $guest = $this->fromToken($request, $page, includeClaimed: true);

            if ($guest->guest_claimed_at) {
                if ($guest->guest_claimed_by_user_id !== $user->id) {
                    $this->fail('This conversation was already connected to an account.', 409);
                }

                $conversation = PageConversation::query()
                    ->whereKey($guest->guest_claimed_conversation_id)
                    ->where('page_id', $page->id)
                    ->where('visitor_id', $user->id)
                    ->first();

                if (! $conversation) {
                    $this->fail('Guest chat session not found.', 404);
                }
            } else {
                $conversation = PageConversation::query()
                    ->where('page_id', $page->id)
                    ->where('visitor_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($conversation) {
                    // Move existing rows, retaining IDs, timestamps and unread status.
                    DB::table('page_chat_messages')->where('page_conversation_id', $guest->id)
                        ->where('sender_as_page', false)->whereNull('sender_id')
                        ->update(['sender_id' => $user->id]);
                    DB::table('page_chat_messages')->where('page_conversation_id', $guest->id)
                        ->update(['page_conversation_id' => $conversation->id]);

                    if ($guest->last_message_at && (! $conversation->last_message_at
                        || $guest->last_message_at->gt($conversation->last_message_at))) {
                        $conversation->forceFill(['last_message_at' => $guest->last_message_at])->save();
                    }

                    // Keep a private receipt so a lost claim response can safely be retried.
                    $guest->forceFill(['last_message_at' => null]);
                } else {
                    $conversation = $guest;
                    $guest->forceFill(['visitor_id' => $user->id]);
                    DB::table('page_chat_messages')->where('page_conversation_id', $guest->id)
                        ->where('sender_as_page', false)->whereNull('sender_id')
                        ->update(['sender_id' => $user->id]);
                }

                $guest->forceFill([
                    'guest_claimed_by_user_id' => $user->id,
                    'guest_claimed_conversation_id' => $conversation->id,
                    'guest_claimed_at' => now(),
                ])->save();
            }

            $conversation->load(['page.user', 'visitor.profile', 'messages.sender.profile']);

            return ApiResponseService::success($this->payloads->pageConversation(
                $conversation,
                $user,
                $this->chats->composerState($user, $conversation),
                withMessages: true,
            ), 'Conversation connected to your account.')->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    private function lockAvailablePage(Page $page): Page
    {
        $page = Page::query()->lockForUpdate()->findOrFail($page->id);
        $page->load('user');

        if ($page->type !== Page::TYPE_BUSINESS || ! $page->user || $page->user->banned_at) {
            $this->fail('Resource not found.', 404);
        }

        if ($page->is_unclaimed) {
            $this->fail('Chat becomes available after this page is claimed.', 409);
        }

        return $page;
    }

    private function fromToken(Request $request, Page $page, bool $includeClaimed = false): PageConversation
    {
        $token = (string) $request->header(self::TOKEN_HEADER, '');

        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            $this->fail('Guest chat session not found.', 404);
        }

        $conversation = PageConversation::query()
            ->where('page_id', $page->id)
            ->where('guest_token_hash', hash('sha256', $token))
            ->when(! $includeClaimed, fn ($query) => $query->whereNull('visitor_id')->whereNull('guest_claimed_at'))
            ->lockForUpdate()
            ->first();

        if (! $conversation) {
            $this->fail('Guest chat session not found.', 404);
        }

        return $conversation;
    }

    private function messageData(Request $request): array
    {
        $request->merge(['body' => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body')]);

        return $request->validate([
            'body' => ['required', 'string', 'max:5000', new CleanContent],
            'locale' => ['sometimes', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
        ]);
    }

    private function appendGuestMessage(PageConversation $conversation, string $body): void
    {
        $message = PageChatMessage::query()->create([
            'page_conversation_id' => $conversation->id,
            'sender_id' => null,
            'sender_as_page' => false,
            'body' => $body,
        ]);
        $conversation->forceFill(['last_message_at' => $message->created_at])->save();
    }

    private function payload(PageConversation $conversation): array
    {
        $conversation->load(['page.user', 'visitor.profile', 'messages.sender.profile']);

        return $this->payloads->pageConversation(
            $conversation,
            null,
            $this->chats->composerState(null, $conversation),
            withMessages: true,
        );
    }

    private function fail(string $message, int $status, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponseService::error($message, $errors, $status)
            ->header('Cache-Control', 'private, no-store'));
    }
}
