<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\GuestSupportConversation;
use App\Models\GuestSupportMessage;
use App\Models\User;
use App\Services\GuestSupportService;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestSupportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_start_chat_and_receive_an_admin_reply_with_the_browser_token(): void
    {
        $response = $this->postJson('/api/v1/guest-support', [
            'name' => 'Guest Visitor',
            'email' => 'guest@example.com',
            'locale' => 'en',
            'body' => 'I need help with a listing.',
        ])->assertCreated()
            ->assertJsonPath('data.conversation.source', 'guest')
            ->assertJsonPath('data.conversation.participant.display_name', 'Guest Visitor')
            ->assertJsonPath('data.conversation.messages.0.sender_type', 'guest');

        $token = $response->json('data.token');
        $conversationId = $response->json('data.conversation.id');

        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(32, strlen($token));
        $this->assertDatabaseHas('guest_support_conversations', [
            'id' => $conversationId,
            'token_hash' => hash('sha256', $token),
            'email' => 'guest@example.com',
        ]);
        $this->assertDatabaseMissing('guest_support_conversations', ['token_hash' => $token]);

        $this->withHeader(GuestSupportService::TOKEN_HEADER, $token)
            ->getJson('/api/v1/guest-support')
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'I need help with a listing.');

        $this->postJson('/api/v1/guest-support/messages', [
            'body' => 'Here is another detail.',
        ])->assertCreated()
            ->assertJsonCount(2, 'data.messages');

        $supportAdmin = User::query()
            ->where('email', config('sveevee.support_admin_email'))
            ->firstOrFail();
        Sanctum::actingAs($supportAdmin);

        $this->getJson('/api/v1/admin/support-chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.support_key', "guest:{$conversationId}")
            ->assertJsonPath('data.conversations.0.participant.email', 'guest@example.com')
            ->assertJsonPath('data.conversations.0.is_guest', true);

        $this->getJson("/api/v1/admin/support-chats/guest/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->postJson("/api/v1/admin/support-chats/guest/{$conversationId}/messages", [
            'body' => 'A human support reply.',
        ])->assertCreated()
            ->assertJsonPath('data.messages.2.sender_type', 'admin');

        $this->getJson('/api/v1/guest-support')
            ->assertOk()
            ->assertJsonPath('data.messages.2.body', 'A human support reply.')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_guest_session_requires_a_valid_token_and_valid_contact_fields(): void
    {
        $this->getJson('/api/v1/guest-support')->assertNotFound();

        $this->withHeader(GuestSupportService::TOKEN_HEADER, str_repeat('x', 43))
            ->getJson('/api/v1/guest-support')
            ->assertNotFound();

        $this->postJson('/api/v1/guest-support', [
            'name' => 'Guest Visitor',
            'email' => 'not-an-email',
            'locale' => 'en',
            'body' => 'A valid question.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/guest-support', [
            'name' => 'Guest Visitor',
            'locale' => 'de',
            'body' => 'A valid question.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    public function test_guest_chat_can_be_claimed_once_and_then_appears_in_account_support(): void
    {
        $started = $this->postJson('/api/v1/guest-support', [
            'name' => 'Future Member',
            'locale' => 'he',
            'body' => 'My guest question.',
        ])->assertCreated();

        $token = $started->json('data.token');
        $guestConversation = GuestSupportConversation::query()->firstOrFail();
        $supportAdmin = User::query()
            ->where('email', config('sveevee.support_admin_email'))
            ->firstOrFail();
        $adminReply = $guestConversation->messages()->create([
            'sender_type' => GuestSupportMessage::SENDER_ADMIN,
            'sender_user_id' => $supportAdmin->id,
            'body' => 'Please create an account when ready.',
        ]);
        $guestConversation->forceFill(['last_message_at' => $adminReply->created_at])->save();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $token);

        $claimed = $this->postJson('/api/v1/guest-support/claim')
            ->assertOk()
            ->assertJsonPath('data.is_support', true)
            ->assertJsonCount(2, 'data.messages');

        $conversationId = $claimed->json('data.id');
        $this->assertDatabaseHas('guest_support_conversations', [
            'id' => $guestConversation->id,
            'claimed_by_user_id' => $user->id,
            'claimed_conversation_id' => $conversationId,
        ]);
        $this->assertSame(2, ChatMessage::query()->where('conversation_id', $conversationId)->count());

        $this->postJson('/api/v1/guest-support/claim')
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId);
        $this->assertSame(2, ChatMessage::query()->where('conversation_id', $conversationId)->count());

        $this->getJson('/api/v1/guest-support')->assertNotFound();
        $this->getJson('/api/v1/chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.id', $conversationId)
            ->assertJsonPath('data.conversations.0.is_support', true);

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);
        $this->postJson('/api/v1/guest-support/claim')->assertStatus(409);
    }

    public function test_admin_can_configure_and_prune_inactive_guest_support_chats(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');

        $old = GuestSupportConversation::query()->create([
            'token_hash' => hash('sha256', 'old-session'),
            'name' => 'Old Guest',
            'locale' => 'en',
            'last_message_at' => now()->subDays(91),
        ]);
        $oldMessage = $old->messages()->create([
            'sender_type' => GuestSupportMessage::SENDER_GUEST,
            'body' => 'Old message',
        ]);

        $recent = GuestSupportConversation::query()->create([
            'token_hash' => hash('sha256', 'recent-session'),
            'name' => 'Recent Guest',
            'locale' => 'en',
            'last_message_at' => now()->subDays(89),
        ]);

        app(SystemSettingsService::class)->updateSection('chat', [
            'guest_retention_days' => 90,
        ], null);

        $this->artisan('support:prune-guest-chats')
            ->expectsOutput('Deleted 1 inactive guest support conversations.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('guest_support_conversations', ['id' => $old->id]);
        $this->assertDatabaseMissing('guest_support_messages', ['id' => $oldMessage->id]);
        $this->assertDatabaseHas('guest_support_conversations', ['id' => $recent->id]);

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/settings/chat', [
            'new_recipients_per_day' => 10,
            'messages_per_minute' => 30,
            'guest_retention_days' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('guest_retention_days');

        $this->patchJson('/api/v1/admin/settings/chat', [
            'new_recipients_per_day' => 10,
            'messages_per_minute' => 30,
            'guest_retention_days' => 120,
        ])->assertOk()
            ->assertJsonPath('data.settings.guest_retention_days', 120);

        Carbon::setTestNow();
    }
}
