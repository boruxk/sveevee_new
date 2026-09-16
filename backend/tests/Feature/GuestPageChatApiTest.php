<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GuestPageChatController;
use App\Models\Page;
use App\Models\PageChatMessage;
use App\Models\PageConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestPageChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_history_is_available_only_to_the_token_holder_and_business_owner(): void
    {
        $page = $this->businessPage();
        $userCount = User::query()->count();
        $created = $this->postJson($this->url($page), ['body' => 'Are you open tomorrow?', 'locale' => 'en'])
            ->assertCreated()
            ->assertJsonPath('data.conversation.is_guest', true)
            ->assertJsonPath('data.conversation.is_page_chat', true)
            ->assertJsonPath('data.conversation.messages.0.sender_id', null)
            ->assertJsonPath('data.conversation.messages.0.sender.is_guest', true)
            ->assertJsonPath('data.conversation.composer_state.reason', 'page_pending_reply');
        $token = $created->json('data.token');
        $id = $created->json('data.conversation.id');
        $this->assertSame(43, strlen($token));
        $this->assertSame($userCount, User::query()->count());
        $this->assertDatabaseHas('page_conversations', ['id' => $id, 'visitor_id' => null, 'guest_token_hash' => hash('sha256', $token)]);
        $this->assertStringNotContainsString('guest_token_hash', $created->getContent());
        $this->assertStringNotContainsString('guest_token_hash', PageConversation::query()->findOrFail($id)->toJson());
        $this->assertStringContainsString('no-store', $created->headers->get('Cache-Control'));

        $this->getJson($this->url($page))->assertNotFound();
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $token)
            ->getJson($this->url($page))->assertOk()->assertJsonCount(1, 'data.messages');
        $this->postJson($this->url($page).'/messages', ['body' => 'Another question.'])
            ->assertStatus(409)->assertJsonPath('errors.reason', 'page_pending_reply');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/page-chats')->assertOk()->assertJsonCount(0, 'data.conversations');
        $this->getJson('/api/v1/page-chats/'.$id)->assertForbidden();
        $this->postJson('/api/v1/page-chats/'.$id.'/messages', ['body' => 'An intruder.'])->assertForbidden();

        Sanctum::actingAs($page->user);
        $this->getJson('/api/v1/pages/'.$page->id.'/chats')
            ->assertOk()->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.other_user.is_guest', true)
            ->assertJsonPath('data.conversations.0.other_user.public_path', null)
            ->assertJsonPath('data.conversations.0.unread_count', 1);
        $this->getJson('/api/v1/page-chats/'.$id)->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull(PageChatMessage::query()->where('page_conversation_id', $id)->firstOrFail()->read_at);
        $this->postJson('/api/v1/page-chats/'.$id.'/messages', ['body' => 'Yes, from nine.'])
            ->assertCreated()->assertJsonPath('data.messages.1.sender_as_page', true)
            ->assertJsonPath('data.messages.1.sender.display_name', $page->name);

        $this->getJson($this->url($page))->assertOk()->assertJsonPath('data.messages.1.body', 'Yes, from nine.')
            ->assertJsonPath('data.unread_count', 0)->assertJsonPath('data.composer_state.can_send', true);
        $this->postJson($this->url($page).'/messages', ['body' => 'Thank you.'])->assertCreated()->assertJsonCount(3, 'data.messages');
        $this->getJson('/api/v1/pages/'.$page->id.'/chats')->assertOk()->assertJsonPath('data.conversations.0.unread_count', 1);
        $this->assertSame($userCount + 1, User::query()->count());
    }

    public function test_guest_token_is_bound_to_its_page_and_is_required_to_send_or_claim(): void
    {
        $page = $this->businessPage();
        $otherPage = $this->businessPage();
        $created = $this->postJson($this->url($page), ['body' => 'Hello.'])->assertCreated();
        $token = $created->json('data.token');

        $this->postJson($this->url($page).'/messages', ['body' => 'Secret?'])->assertNotFound();
        $this->postJson($this->url($page).'/claim')->assertUnauthorized();
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, str_repeat('x', 43))->getJson($this->url($page))->assertNotFound();
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $token)->getJson($this->url($otherPage))->assertNotFound();
        $this->postJson($this->url($otherPage).'/messages', ['body' => 'Wrong page.'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url($otherPage).'/claim')->assertNotFound();
        $this->assertSame(1, PageChatMessage::query()->count());
    }

    public function test_new_account_can_keep_the_entire_history_before_completing_its_profile(): void
    {
        $page = $this->businessPage();
        $created = $this->postJson($this->url($page), ['body' => 'My first message.'])->assertCreated();
        $token = $created->json('data.token');
        $id = $created->json('data.conversation.id');
        Sanctum::actingAs($page->user);
        $this->postJson('/api/v1/page-chats/'.$id.'/messages', ['body' => 'Our reply.'])->assertCreated();
        $messages = PageChatMessage::query()->orderBy('id')->get();

        $member = User::factory()->create(['given_name' => null, 'family_name' => null, 'email_verified_at' => null]);
        Sanctum::actingAs($member);
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $token)->postJson($this->url($page).'/claim')
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.is_guest', false)
            ->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.0.sender_id', $member->id)
            ->assertJsonPath('data.messages.1.sender_id', $page->user_id)->assertJsonPath('data.unread_count', 1);
        $this->postJson($this->url($page).'/claim')->assertOk()->assertJsonPath('data.id', $id)->assertJsonCount(2, 'data.messages');
        $this->getJson($this->url($page))->assertNotFound();
        $this->postJson($this->url($page).'/messages', ['body' => 'Expired guest token.'])->assertNotFound();
        $this->getJson('/api/v1/page-chats')->assertOk()->assertJsonCount(1, 'data.conversations')->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/v1/page-chats/'.$id)->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->postJson('/api/v1/page-chats/'.$id.'/messages', ['body' => 'Now from my account.'])->assertCreated();
        foreach ($messages as $message) {
            $this->assertDatabaseHas('page_chat_messages', ['id' => $message->id, 'body' => $message->body, 'created_at' => $message->getRawOriginal('created_at')]);
        }
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url($page).'/claim')->assertStatus(409);
        $this->getJson('/api/v1/page-chats/'.$id)->assertForbidden();
        $this->assertSame(3, PageChatMessage::query()->count());
    }

    public function test_claim_merges_an_existing_account_conversation_without_losing_or_copying_messages(): void
    {
        $page = $this->businessPage();
        $created = $this->postJson($this->url($page), ['body' => 'Guest history.'])->assertCreated();
        $guestId = $created->json('data.conversation.id');
        $token = $created->json('data.token');
        Sanctum::actingAs($page->user);
        $this->postJson('/api/v1/page-chats/'.$guestId.'/messages', ['body' => 'Business guest reply.'])->assertCreated();
        $this->travel(1)->minutes();
        $member = User::factory()->create();
        Sanctum::actingAs($member);
        $existing = $this->postJson('/api/v1/pages/'.$page->id.'/chat/messages', ['body' => 'Existing account history.'])->assertCreated();
        $accountId = $existing->json('data.id');
        $before = PageChatMessage::query()->orderBy('id')->get();
        $lastMessageAt = PageConversation::query()->findOrFail($accountId)->getRawOriginal('last_message_at');

        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $token)->postJson($this->url($page).'/claim')
            ->assertOk()->assertJsonPath('data.id', $accountId)->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Guest history.')
            ->assertJsonPath('data.messages.1.body', 'Business guest reply.')
            ->assertJsonPath('data.messages.2.body', 'Existing account history.');
        $this->postJson($this->url($page).'/claim')->assertOk()->assertJsonPath('data.id', $accountId);
        $this->assertSame(3, PageChatMessage::query()->count());
        $this->assertDatabaseHas('page_conversations', ['id' => $guestId, 'last_message_at' => null, 'guest_claimed_conversation_id' => $accountId]);
        $this->assertDatabaseHas('page_conversations', ['id' => $accountId, 'last_message_at' => $lastMessageAt]);
        foreach ($before as $message) {
            $this->assertDatabaseHas('page_chat_messages', [
                'id' => $message->id, 'page_conversation_id' => $accountId,
                'sender_id' => $message->sender_as_page ? $page->user_id : $member->id,
                'created_at' => $message->getRawOriginal('created_at'), 'read_at' => $message->getRawOriginal('read_at'),
            ]);
        }
        $this->getJson('/api/v1/page-chats')->assertOk()->assertJsonCount(1, 'data.conversations');
        $this->getJson($this->url($page))->assertNotFound();
        Sanctum::actingAs($page->user);
        $this->getJson('/api/v1/pages/'.$page->id.'/chats')->assertOk()->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.other_user.id', $member->id);
        $this->getJson('/api/v1/page-chats/'.$guestId)->assertForbidden();
        $this->postJson('/api/v1/page-chats/'.$guestId.'/messages', ['body' => 'Stale inbox.'])->assertForbidden();
        $this->travelBack();
    }

    public function test_registration_access_token_can_claim_the_guest_chat_before_profile_completion(): void
    {
        Notification::fake();
        $page = $this->businessPage();
        $created = $this->postJson($this->url($page), ['body' => 'Please keep this after registration.'])->assertCreated();
        $registered = $this->postJson('/api/v1/auth/register', [
            'email' => 'guest-chat-member@example.test',
            'password' => 'TestPassword123',
            'password_confirmation' => 'TestPassword123',
            'given_name' => 'New',
            'family_name' => 'Member',
            'locale' => 'en',
            'consented' => true,
        ])->assertCreated()->assertJsonPath('data.user.profile_complete', false);

        $this->withToken($registered->json('data.token'))
            ->withHeader(GuestPageChatController::TOKEN_HEADER, $created->json('data.token'))
            ->postJson($this->url($page).'/claim')
            ->assertOk()->assertJsonPath('data.id', $created->json('data.conversation.id'))
            ->assertJsonPath('data.messages.0.sender_id', $registered->json('data.user.id'));
        $this->getJson('/api/v1/page-chats')->assertOk()->assertJsonCount(1, 'data.conversations');
        $this->getJson($this->url($page))->assertNotFound();
    }

    public function test_banned_account_cannot_claim_guest_history(): void
    {
        $page = $this->businessPage();
        $created = $this->postJson($this->url($page), ['body' => 'Guest question.'])->assertCreated();
        Sanctum::actingAs(User::factory()->create(['banned_at' => now()]));
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $created->json('data.token'))
            ->postJson($this->url($page).'/claim')->assertForbidden();
        $this->assertDatabaseHas('page_conversations', ['id' => $created->json('data.conversation.id'), 'visitor_id' => null, 'guest_claimed_at' => null]);
    }

    public function test_guest_messages_reject_blocked_language_and_have_a_send_rate_limit(): void
    {
        $page = $this->businessPage();
        $this->postJson($this->url($page), ['body' => 'quelle merde'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $created = $this->postJson($this->url($page), ['body' => 'Guest question.'])->assertCreated();
        $id = $created->json('data.conversation.id');
        PageChatMessage::query()->create([
            'page_conversation_id' => $id,
            'sender_id' => $page->user_id,
            'sender_as_page' => true,
            'body' => 'Our reply.',
        ]);
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $created->json('data.token'))
            ->postJson($this->url($page).'/messages', ['body' => 'quelle merde'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($this->url($page).'/messages', ['body' => str_repeat('x', 5001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        for ($attempt = 0; $attempt < 28; $attempt++) {
            $this->postJson($this->url($page).'/messages', ['body' => 'A reply.'])->assertCreated();
        }
        $this->postJson($this->url($page).'/messages', ['body' => 'Too many messages.'])->assertStatus(429);
        $this->assertSame(30, PageChatMessage::query()->count());
    }

    public function test_guests_cannot_start_chats_with_unclaimed_community_or_banned_businesses(): void
    {
        $unclaimed = $this->businessPage(['is_unclaimed' => true]);
        $community = $this->businessPage(['type' => Page::TYPE_COMMUNITY]);
        $banned = $this->businessPage();
        $banned->user->forceFill(['banned_at' => now()])->save();
        $this->postJson($this->url($unclaimed), ['body' => 'Hello.'])->assertStatus(409);
        $this->postJson($this->url($community), ['body' => 'Hello.'])->assertNotFound();
        $this->postJson($this->url($banned), ['body' => 'Hello.'])->assertNotFound();
        $this->assertSame(0, PageConversation::query()->count());
    }

    public function test_ownership_changes_and_bans_do_not_leave_chat_access_open(): void
    {
        $page = $this->businessPage();
        $oldOwner = $page->user;
        $created = $this->postJson($this->url($page), ['body' => 'Private business question.'])->assertCreated();
        $id = $created->json('data.conversation.id');
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $created->json('data.token'));
        Sanctum::actingAs($oldOwner);
        $this->postJson($this->url($page).'/claim')->assertUnprocessable();

        $newOwner = User::factory()->create();
        $page->forceFill(['user_id' => $newOwner->id])->save();
        $this->getJson('/api/v1/page-chats/'.$id)->assertForbidden();
        $this->postJson('/api/v1/page-chats/'.$id.'/messages', ['body' => 'Old owner.'])->assertForbidden();
        Sanctum::actingAs($newOwner);
        $this->getJson('/api/v1/pages/'.$page->id.'/chats')->assertOk()->assertJsonCount(1, 'data.conversations');

        $newOwner->forceFill(['banned_at' => now()])->save();
        $this->getJson($this->url($page))->assertNotFound();
        $this->postJson($this->url($page).'/messages', ['body' => 'Blocked.'])->assertNotFound();
        $this->getJson('/api/v1/page-chats/'.$id)->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url($page).'/claim')->assertNotFound();
        $this->assertSame(1, PageChatMessage::query()->count());
    }

    public function test_message_validation_and_start_rate_limit_apply_to_guests(): void
    {
        $page = $this->businessPage();
        $this->postJson($this->url($page), ['body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($this->url($page), ['body' => str_repeat('x', 5001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($this->url($page), ['body' => 'Hello.', 'locale' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->postJson($this->url($page), ['body' => 'Too many new conversations.'])->assertStatus(429);
        $this->assertSame(0, PageConversation::query()->count());
    }

    public function test_guest_rows_remain_consistent_when_the_page_is_deleted(): void
    {
        $page = $this->businessPage();
        $created = $this->postJson($this->url($page), ['body' => 'Hello.'])->assertCreated();
        $token = $created->json('data.token');
        $page->delete();
        $this->assertSame(0, PageConversation::query()->count());
        $this->assertSame(0, PageChatMessage::query()->count());
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $token)->getJson($this->url($page))->assertNotFound();
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    private function businessPage(array $attributes = []): Page
    {
        return Page::query()->create([
            'user_id' => User::factory()->create()->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Guest Chat Business',
            ...$attributes,
        ]);
    }

    private function url(Page $page): string
    {
        return '/api/v1/pages/'.$page->id.'/guest-chat';
    }
}
