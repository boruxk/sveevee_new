<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\GuestSupportConversation;
use App\Models\GuestSupportMessage;
use App\Rules\CleanContent;
use App\Rules\NoGuestChatLinks;
use App\Services\ApiResponseService;
use App\Services\GuestSupportService;
use App\Services\PayloadService;
use App\Services\SupportAcknowledgementService;
use App\Services\SupportReplyLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GuestSupportController extends Controller
{
    public function __construct(
        private readonly GuestSupportService $guestSupport,
        private readonly PayloadService $payloads,
        private readonly SupportReplyLimitService $replyLimits,
        private readonly SupportAcknowledgementService $acknowledgements,
    ) {}

    public function store(Request $request)
    {
        $supportAdmin = $this->guestSupport->supportAdmin();

        if (! $supportAdmin || $supportAdmin->banned_at) {
            return ApiResponseService::error('Support chat is not available right now.', status: 503);
        }

        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'body' => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body'),
        ]);

        $data = $request->validate([
            'name' => ['bail', 'required', 'string', 'max:100', new CleanContent, new NoGuestChatLinks],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'locale' => ['required', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
            'body' => ['bail', 'required', 'string', 'max:5000', new CleanContent, new NoGuestChatLinks],
        ]);

        [$token, $tokenHash] = $this->guestSupport->issueToken();

        $conversation = DB::transaction(function () use ($data, $tokenHash): GuestSupportConversation {
            $conversation = GuestSupportConversation::query()->create([
                'token_hash' => $tokenHash,
                'name' => $data['name'],
                'email' => filled($data['email'] ?? null) ? strtolower($data['email']) : null,
                'locale' => $data['locale'],
            ]);

            $message = $conversation->messages()->create([
                'sender_type' => GuestSupportMessage::SENDER_GUEST,
                'body' => $data['body'],
            ]);

            $acknowledgement = $this->acknowledgements->guest($conversation);
            $conversation->forceFill(['last_message_at' => ($acknowledgement ?? $message)->created_at])->save();

            return $conversation;
        });

        $conversation->load(['messages.sender.profile']);

        return ApiResponseService::success([
            'token' => $token,
            'conversation' => $this->guestSupport->payload($conversation, withMessages: true),
        ], 'Support conversation started.', 201);
    }

    public function show(Request $request)
    {
        $conversation = $this->guestSupport->fromRequest($request);

        if (! $conversation) {
            return ApiResponseService::error('Guest support session not found.', status: 404);
        }

        $this->guestSupport->markReadByGuest($conversation);
        $conversation->load(['messages.sender.profile']);

        return ApiResponseService::success(
            $this->guestSupport->payload($conversation, withMessages: true)
        );
    }

    public function send(Request $request)
    {
        return DB::transaction(function () use ($request) {
            // Serialize guest sends, admin replies and claiming on the same row.
            // Recheck token and claim state after acquiring the current row lock.
            $conversation = $this->guestSupport->fromRequest($request, lockForUpdate: true);

            if (! $conversation) {
                return ApiResponseService::error('Guest support session not found.', status: 404);
            }

            $state = $this->guestSupport->composerState($conversation);
            if (! $state['can_send']) {
                return ApiResponseService::error($state['message'], ['reason' => $state['reason']], 409);
            }

            $request->merge(['body' => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body')]);
            $data = $request->validate([
                'body' => ['bail', 'required', 'string', 'max:5000', new CleanContent, new NoGuestChatLinks],
            ]);

            $message = $conversation->messages()->create([
                'sender_type' => GuestSupportMessage::SENDER_GUEST,
                'body' => $data['body'],
            ]);

            $acknowledgement = $this->acknowledgements->guest($conversation);
            $conversation->forceFill(['last_message_at' => ($acknowledgement ?? $message)->created_at])->save();
            $conversation->load(['messages.sender.profile']);

            return ApiResponseService::success(
                $this->guestSupport->payload($conversation, withMessages: true),
                'Message sent.',
                201
            );
        }, 3);
    }

    public function claim(Request $request)
    {
        $guestConversation = $this->guestSupport->fromRequest($request, includeClaimed: true);

        if (! $guestConversation) {
            return ApiResponseService::error('Guest support session not found.', status: 404);
        }

        $supportAdmin = $this->guestSupport->supportAdmin();
        $user = $request->user();

        if (! $supportAdmin || $supportAdmin->banned_at) {
            return ApiResponseService::error('Support chat is not available right now.', status: 503);
        }

        if ($user->id === $supportAdmin->id) {
            return ApiResponseService::error('This support conversation cannot be claimed.', status: 422);
        }

        $result = DB::transaction(function () use ($guestConversation, $supportAdmin, $user): array {
            $guest = GuestSupportConversation::query()
                ->lockForUpdate()
                ->find($guestConversation->id);

            if (! $guest) {
                return ['status' => 'missing'];
            }

            if ($guest->claimed_at) {
                if ($guest->claimed_by_user_id !== $user->id || ! $guest->claimed_conversation_id) {
                    return ['status' => 'claimed'];
                }

                $conversation = Conversation::query()->find($guest->claimed_conversation_id);

                return $conversation
                    ? ['status' => 'ok', 'conversation' => $conversation]
                    : ['status' => 'claimed'];
            }

            [$one, $two] = Conversation::pairFor($user, $supportAdmin);
            $conversation = Conversation::query()->firstOrCreate(
                ['user_one_id' => $one, 'user_two_id' => $two, 'is_support' => true],
                ['started_by_user_id' => $user->id]
            );

            // Match the account-support send lock before merging guest history.
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $guest->load('messages');

            foreach ($guest->messages as $guestMessage) {
                $message = new ChatMessage([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $guestMessage->sender_type === GuestSupportMessage::SENDER_GUEST
                        ? $user->id
                        : $supportAdmin->id,
                    'body' => $guestMessage->body,
                    'is_automatic' => $guestMessage->is_automatic,
                    'read_at' => $guestMessage->read_at,
                ]);
                $message->created_at = $guestMessage->created_at;
                $message->updated_at = $guestMessage->updated_at;
                $message->save();
            }

            if (
                $guest->last_message_at
                && (! $conversation->last_message_at || $guest->last_message_at->gt($conversation->last_message_at))
            ) {
                $conversation->forceFill(['last_message_at' => $guest->last_message_at])->save();
            }

            $guest->forceFill([
                'claimed_by_user_id' => $user->id,
                'claimed_conversation_id' => $conversation->id,
                'claimed_at' => now(),
            ])->save();

            return ['status' => 'ok', 'conversation' => $conversation];
        });

        if ($result['status'] === 'missing') {
            return ApiResponseService::error('Guest support session not found.', status: 404);
        }

        if ($result['status'] !== 'ok') {
            return ApiResponseService::error('This support conversation was already claimed.', status: 409);
        }

        $conversation = $result['conversation'];
        $conversation->load(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);

        return ApiResponseService::success($this->payloads->conversation(
            $conversation,
            $user,
            $this->replyLimits->accountComposerState($user, $conversation),
            withMessages: true
        ), 'Support conversation connected.');
    }
}
