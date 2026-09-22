<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GuestPageChatController;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\PageChatMessage;
use App\Models\PageConversation;
use App\Models\User;
use App\Services\GuestSupportService;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatSafetyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_support_waits_after_five_messages_on_all_send_routes_and_reading_does_not_reset_it(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        for ($i = 1; $i <= 5; $i++) {
            $sent = $this->postJson('/api/v1/chats/support/messages', ['body' => 'Help with https://example.com/'.$i])
                ->assertCreated()->assertJsonPath('data.composer_state.remaining', 5 - $i)
                ->assertJsonPath('data.composer_state.can_send', $i < 5);
        }
        $id = $sent->json('data.id');
        foreach (['/api/v1/chats/support/messages', '/api/v1/chats/'.$id.'/messages'] as $url) {
            $this->postJson($url, ['body' => 'Over the limit'])->assertStatus(409)
                ->assertJsonPath('errors.reason', 'support_pending_reply');
        }
        Sanctum::actingAs($this->supportAdmin());
        $this->getJson('/api/v1/admin/support-chats/account/'.$id)->assertOk()
            ->assertJsonPath('data.composer_state.can_send', true);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/chats/support')->assertOk()->assertJsonPath('data.composer_state.remaining', 0);
        $this->postJson('/api/v1/chats/'.$id.'/messages', ['body' => 'Read is not a reply'])->assertStatus(409);
        $this->assertSame(5, ChatMessage::query()->where('conversation_id', $id)->where('is_automatic', false)->count());
        Sanctum::actingAs($this->supportAdmin());
        $this->postJson('/api/v1/admin/support-chats/account/'.$id.'/messages', ['body' => 'Instructions: https://sveevee.co.il/help'])
            ->assertCreated()->assertJsonPath('data.composer_state.can_send', true);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/chats/support')->assertOk()->assertJsonPath('data.composer_state.remaining', 5);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/chats/'.$id.'/messages', ['body' => 'Follow-up '.$i])->assertCreated()
                ->assertJsonPath('data.composer_state.remaining', 5 - $i);
        }
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Over the second limit'])->assertStatus(409);
    }

    public function test_custom_limit_applies_to_registered_support_and_support_can_always_reply(): void
    {
        app(SystemSettingsService::class)->updateSection('chat', ['support_messages_before_reply' => 1], null);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $sent = $this->postJson('/api/v1/chats/support/messages', ['body' => 'One question'])->assertCreated()
            ->assertJsonPath('data.composer_state.limit', 1)->assertJsonPath('data.composer_state.can_send', false);
        $id = $sent->json('data.id');
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Second question'])->assertStatus(409);
        Sanctum::actingAs($this->supportAdmin());
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/chats/'.$id.'/messages', ['body' => 'Support response '.$i])->assertCreated()
                ->assertJsonPath('data.composer_state.can_send', true);
        }
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'One follow-up'])->assertCreated();
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Another follow-up'])->assertStatus(409);
    }

    public function test_business_guest_links_are_rejected_on_start_and_send_but_owner_links_are_allowed(): void
    {
        $page = Page::query()->create(['user_id' => User::factory()->create()->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'A Business']);
        $url = '/api/v1/pages/'.$page->id.'/guest-chat';
        foreach (['https://example.com', 'example.co.il'] as $link) {
            $this->postJson($url, ['body' => $link])->assertUnprocessable()
                ->assertJsonPath('errors.body.0', 'guest_chat_links_not_allowed');
        }
        $this->assertSame(0, PageConversation::query()->count());
        $started = $this->postJson($url, ['body' => 'Are you open at 10:30?'])->assertCreated();
        $id = $started->json('data.conversation.id');
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $started->json('data.token'));
        Sanctum::actingAs($page->user);
        $this->postJson('/api/v1/page-chats/'.$id.'/messages', ['body' => 'Opening hours: https://example.com/hours'])->assertCreated();
        foreach (['www.example.com', 'example%2ecom', 'https://example.com/שלום'] as $link) {
            $this->postJson($url.'/messages', ['body' => $link])->assertUnprocessable()
                ->assertJsonPath('errors.body.0', 'guest_chat_links_not_allowed');
        }
        $this->assertSame(2, PageChatMessage::query()->where('page_conversation_id', $id)->count());
        $this->postJson($url.'/messages', ['body' => 'Thank you, I will call 050-1234567.'])->assertCreated();
    }

    public function test_claiming_older_guest_replies_cannot_reset_an_existing_account_support_limit(): void
    {
        $this->travelTo(now()->subDay());
        $guest = $this->postJson('/api/v1/guest-support', ['name' => 'Visitor', 'locale' => 'en', 'body' => 'An older question'])->assertCreated();
        Sanctum::actingAs($this->supportAdmin());
        $this->postJson('/api/v1/admin/support-chats/guest/'.$guest->json('data.conversation.id').'/messages', ['body' => 'An older answer'])->assertCreated();
        $this->travelBack();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/chats/support/messages', ['body' => 'A newer question '.$i])->assertCreated();
        }
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $guest->json('data.token'))
            ->postJson('/api/v1/guest-support/claim')->assertOk()
            ->assertJsonPath('data.composer_state.can_send', false)
            ->assertJsonPath('data.composer_state.remaining', 0);
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Still waiting'])->assertStatus(409);
    }

    public function test_business_guest_message_types_fail_validation_without_creating_a_conversation(): void
    {
        $page = Page::query()->create(['user_id' => User::factory()->create()->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'A Business']);
        foreach ([['invalid'], 42, null] as $body) {
            $this->postJson('/api/v1/pages/'.$page->id.'/guest-chat', ['body' => $body])
                ->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->assertSame(0, PageConversation::query()->count());
    }

    private function supportAdmin(): User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
    }
}
