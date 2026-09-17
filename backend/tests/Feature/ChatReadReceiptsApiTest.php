<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GuestPageChatController;
use App\Jobs\SendUnreadChatEmail;
use App\Models\ChatEmailNotificationState;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\User;
use App\Services\GuestSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatReadReceiptsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_receipts_change_only_for_the_reader_and_are_returned_to_the_sender(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);
        $sent = $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", ['body' => 'A message.'])
            ->assertCreated()->assertJsonPath('data.messages.0.read_at', null);
        $id = $sent->json('data.id');
        $messageId = $sent->json('data.messages.0.id');
        $this->getJson("/api/v1/chats/{$id}")->assertOk()->assertJsonPath('data.messages.0.read_at', null);
        $this->patchJson("/api/v1/chats/{$id}/read")->assertOk();
        $this->assertNull(ChatMessage::findOrFail($messageId)->read_at);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/chats/{$id}")->assertForbidden();
        $this->patchJson("/api/v1/chats/{$id}/read")->assertForbidden();
        $this->assertNull(ChatMessage::findOrFail($messageId)->read_at);

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/chats')->assertOk()
            ->assertJsonPath('data.conversations.0.latest_message.read_at', null)
            ->assertJsonPath('data.unread_count', 1);
        $this->assertNull(ChatMessage::findOrFail($messageId)->read_at);
        $read = $this->getJson("/api/v1/chats/users/{$sender->id}")->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value));
        $readAt = $read->json('data.messages.0.read_at');
        $this->travel(1)->minutes();
        $this->patchJson("/api/v1/chats/{$id}/read")->assertOk();

        Sanctum::actingAs($sender);
        $this->getJson("/api/v1/chats/{$id}")->assertOk()->assertJsonPath('data.messages.0.read_at', $readAt);
        $this->travelBack();
    }

    public function test_clearing_a_chat_does_not_report_unseen_messages_as_read_or_leave_unread_alerts(): void
    {
        Mail::fake();
        Queue::fake();
        $recipient = User::factory()->create(['email_verified_at' => now()]);
        $recipient->profile()->update(['email_chat_notifications' => true]);
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $sent = $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", ['body' => 'Unseen old message.'])->assertCreated();
        $id = $sent->json('data.id');
        $messageId = $sent->json('data.messages.0.id');
        $state = ChatEmailNotificationState::query()->firstOrFail();

        Sanctum::actingAs($recipient);
        $this->deleteJson("/api/v1/chats/{$id}", ['mode' => 'self'])->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNull(ChatMessage::findOrFail($messageId)->read_at);
        $this->assertDatabaseMissing('chat_email_notification_states', ['id' => $state->id]);
        app()->call([new SendUnreadChatEmail($id, $recipient->id, $state->pending_token), 'handle']);
        Mail::assertNothingSent();
        $this->getJson("/api/v1/chats/{$id}")->assertOk()->assertJsonCount(0, 'data.messages');
        $this->patchJson("/api/v1/chats/{$id}/read")->assertOk();
        $this->assertNull(ChatMessage::findOrFail($messageId)->read_at);

        $newMessage = ChatMessage::query()->create([
            'conversation_id' => $id, 'sender_id' => $sender->id, 'body' => 'A later message.',
        ]);
        $this->getJson('/api/v1/chats')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson("/api/v1/chats/{$id}")->assertOk()->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.id', $newMessage->id)
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value));
        $this->assertNull(ChatMessage::findOrFail($messageId)->read_at);
    }

    public function test_page_receipts_use_the_business_side_even_after_the_owner_changes(): void
    {
        $oldOwner = User::factory()->create();
        $visitor = User::factory()->create();
        $page = Page::query()->create(['user_id' => $oldOwner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Chat Business']);
        Sanctum::actingAs($visitor);
        $sent = $this->postJson("/api/v1/pages/{$page->id}/chat/messages", ['body' => 'A visitor question.'])
            ->assertCreated()->assertJsonPath('data.viewer_as_page', false);
        $id = $sent->json('data.id');
        Sanctum::actingAs($oldOwner);
        $this->postJson("/api/v1/page-chats/{$id}/messages", ['body' => 'A business reply.'])
            ->assertCreated()->assertJsonPath('data.viewer_as_page', true)
            ->assertJsonPath('data.messages.0.read_at', null)->assertJsonPath('data.messages.1.read_at', null);
        $newOwner = User::factory()->create();
        $page->forceFill(['user_id' => $newOwner->id])->save();
        $this->patchJson("/api/v1/page-chats/{$id}/read")->assertForbidden();
        Sanctum::actingAs($newOwner);
        $this->getJson("/api/v1/pages/{$page->id}/chats")->assertOk()
            ->assertJsonPath('data.conversations.0.viewer_as_page', true)
            ->assertJsonPath('data.conversations.0.unread_count', 1);
        $this->patchJson("/api/v1/page-chats/{$id}/read")->assertOk();
        $this->getJson("/api/v1/page-chats/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.messages.1.read_at', null);
        Sanctum::actingAs($visitor);
        $this->getJson('/api/v1/page-chats')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson("/api/v1/pages/{$page->id}/chat")->assertOk()
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
        Sanctum::actingAs($newOwner);
        $this->getJson("/api/v1/page-chats/{$id}")->assertOk()
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
    }

    public function test_guest_page_receipts_are_visible_to_both_sides_without_marking_their_own_messages(): void
    {
        $owner = User::factory()->create();
        $page = Page::query()->create(['user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Guest Chat']);
        $url = "/api/v1/pages/{$page->id}/guest-chat";
        $sent = $this->postJson($url, ['body' => 'A guest question.'])->assertCreated()
            ->assertJsonPath('data.conversation.viewer_as_page', false)
            ->assertJsonPath('data.conversation.messages.0.read_at', null);
        $id = $sent->json('data.conversation.id');
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $sent->json('data.token'));
        $this->getJson($url)->assertOk()->assertJsonPath('data.messages.0.read_at', null);
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/page-chats/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value));
        $this->postJson("/api/v1/page-chats/{$id}/messages", ['body' => 'The owner reply.'])->assertCreated()
            ->assertJsonPath('data.messages.1.read_at', null);
        $this->getJson("/api/v1/page-chats/{$id}")->assertOk()->assertJsonPath('data.messages.1.read_at', null);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
        $this->getJson("/api/v1/page-chats/{$id}")->assertOk()
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
    }

    public function test_account_support_receipts_are_refreshed_in_the_admin_and_member_payloads(): void
    {
        $member = User::factory()->create();
        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($member);
        $sent = $this->postJson('/api/v1/chats/support/messages', ['body' => 'A support question.'])->assertCreated()
            ->assertJsonPath('data.messages.0.read_at', null);
        $id = $sent->json('data.id');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/support-chats')->assertOk()
            ->assertJsonPath('data.conversations.0.latest_message.read_at', null);
        $this->getJson("/api/v1/admin/support-chats/account/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value));
        $this->postJson("/api/v1/admin/support-chats/account/{$id}/messages", ['body' => 'A support reply.'])->assertCreated()
            ->assertJsonPath('data.messages.1.read_at', null);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/chats/support')->assertOk()
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/support-chats/account/{$id}")->assertOk()
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
    }

    public function test_guest_support_receipts_are_refreshed_in_the_admin_and_guest_payloads(): void
    {
        $sent = $this->postJson('/api/v1/guest-support', ['name' => 'Visitor', 'locale' => 'en', 'body' => 'Guest support question.'])
            ->assertCreated()->assertJsonPath('data.conversation.messages.0.read_at', null);
        $id = $sent->json('data.conversation.id');
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $sent->json('data.token'));
        $this->getJson('/api/v1/guest-support')->assertOk()->assertJsonPath('data.messages.0.read_at', null);
        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/support-chats/guest/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value));
        $this->postJson("/api/v1/admin/support-chats/guest/{$id}/messages", ['body' => 'Guest support reply.'])->assertCreated()
            ->assertJsonPath('data.messages.1.read_at', null);
        $this->getJson('/api/v1/guest-support')->assertOk()
            ->assertJsonPath('data.messages.0.read_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
        $this->getJson("/api/v1/admin/support-chats/guest/{$id}")->assertOk()
            ->assertJsonPath('data.messages.1.read_at', fn ($value) => is_string($value));
    }
}
