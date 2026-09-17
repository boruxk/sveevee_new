<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GuestPageChatController;
use App\Models\Page;
use App\Models\User;
use App\Services\GuestSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatPresenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_counterpart_presence_is_precise_and_not_exposed_in_public_profiles(): void
    {
        $this->freezeTime();
        $viewer = User::factory()->create();
        $other = User::factory()->create(['last_seen_at' => now()->subDay()]);

        $this->getJson('/api/v1/users/'.$other->id)->assertOk()->assertJsonMissingPath('data.presence');
        Sanctum::actingAs($viewer);
        $conversation = $this->getJson('/api/v1/chats/users/'.$other->id)->assertOk()
            ->assertJsonPath('data.other_user.presence.is_online', false)
            ->assertJsonPath('data.other_user.presence.last_seen_at', $other->last_seen_at->toISOString());
        $other->forceFill(['last_seen_at' => null])->saveQuietly();
        $this->getJson('/api/v1/chats/'.$conversation->json('data.id'))->assertOk()
            ->assertJsonPath('data.other_user.presence.is_online', false)
            ->assertJsonPath('data.other_user.presence.last_seen_at', null);
        $this->getJson('/api/v1/admin/users?paginated=1')->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/chats/'.$conversation->json('data.id'))->assertForbidden();
    }

    public function test_page_chat_uses_the_business_owner_presence_for_visitors_and_visitor_presence_for_owner(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['last_seen_at' => now()->subDays(2)]);
        $visitor = User::factory()->create(['last_seen_at' => now()]);
        $page = $this->businessPage($owner);
        Sanctum::actingAs($visitor);
        $conversation = $this->postJson('/api/v1/pages/'.$page->id.'/chat/messages', ['body' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.other_user.is_page', true)
            ->assertJsonPath('data.other_user.presence.is_online', false)
            ->assertJsonPath('data.other_user.presence.last_seen_at', $owner->last_seen_at->toISOString())
            ->assertJsonMissingPath('data.messages.0.sender.presence');
        $this->getJson('/api/v1/page-chats')->assertOk()
            ->assertJsonPath('data.conversations.0.other_user.presence.last_seen_at', $owner->last_seen_at->toISOString());

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/page-chats/'.$conversation->json('data.id'))->assertOk()
            ->assertJsonPath('data.other_user.id', $visitor->id)
            ->assertJsonPath('data.other_user.presence.is_online', true)
            ->assertJsonPath('data.other_user.presence.last_seen_at', $visitor->last_seen_at->toISOString());
    }

    public function test_guest_page_chat_shows_owner_presence_without_inventing_guest_activity(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['last_seen_at' => now()->subHour()]);
        $page = $this->businessPage($owner);
        $url = '/api/v1/pages/'.$page->id.'/guest-chat';
        $started = $this->postJson($url, ['body' => 'Hello'])->assertCreated()
            ->assertJsonPath('data.conversation.other_user.presence.last_seen_at', $owner->last_seen_at->toISOString());
        $this->getJson($url)->assertNotFound();
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $started->json('data.token'))
            ->getJson($url)->assertOk()
            ->assertJsonPath('data.other_user.presence.is_online', false)
            ->assertJsonPath('data.other_user.presence.last_seen_at', $owner->last_seen_at->toISOString());

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/pages/'.$page->id.'/chats')->assertOk()
            ->assertJsonPath('data.conversations.0.other_user.is_guest', true)
            ->assertJsonMissingPath('data.conversations.0.other_user.presence');
    }

    public function test_guest_support_exposes_support_presence_only_to_guest_and_preserves_admin_participant(): void
    {
        $this->freezeTime();
        $support = $this->supportAdmin();
        $support->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        $started = $this->postJson('/api/v1/guest-support', [
            'name' => 'Visitor', 'locale' => 'en', 'body' => 'I need help',
        ])->assertCreated()
            ->assertJsonPath('data.conversation.support_presence.is_online', false)
            ->assertJsonPath('data.conversation.support_presence.last_seen_at', $support->last_seen_at->toISOString());
        $this->getJson('/api/v1/guest-support')->assertNotFound();
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'))
            ->getJson('/api/v1/guest-support')->assertOk()
            ->assertJsonPath('data.support_presence.last_seen_at', $support->last_seen_at->toISOString());

        Sanctum::actingAs($support);
        $this->getJson('/api/v1/admin/support-chats/guest/'.$started->json('data.conversation.id'))->assertOk()
            ->assertJsonPath('data.participant.display_name', 'Visitor')
            ->assertJsonMissingPath('data.participant.presence')
            ->assertJsonMissingPath('data.support_presence');
    }

    public function test_registered_support_chat_and_admin_table_share_the_same_timestamp(): void
    {
        $this->freezeTime();
        $support = $this->supportAdmin();
        $support->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        $viewer = User::factory()->create(['email' => 'presence-test@example.test', 'last_seen_at' => now()->subDay()]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/chats/support')->assertOk()
            ->assertJsonPath('data.other_user.id', $support->id)
            ->assertJsonPath('data.other_user.presence.last_seen_at', $support->last_seen_at->toISOString());

        Sanctum::actingAs($support);
        $this->getJson('/api/v1/admin/users?paginated=1&q=presence-test%40example.test')->assertOk()
            ->assertJsonPath('data.items.0.id', $viewer->id)
            ->assertJsonPath('data.items.0.presence.is_online', false)
            ->assertJsonPath('data.items.0.presence.last_seen_at', $viewer->last_seen_at->toISOString());
    }

    private function businessPage(User $owner): Page
    {
        return Page::query()->create([
            'user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Presence Business',
        ]);
    }

    private function supportAdmin(): User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
    }
}
