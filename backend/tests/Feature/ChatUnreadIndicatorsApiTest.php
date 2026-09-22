<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GuestPageChatController;
use App\Models\Conversation;
use App\Models\GuestSupportConversation;
use App\Models\Page;
use App\Models\PageConversation;
use App\Models\User;
use App\Services\GuestSupportService;
use App\Services\PayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatUnreadIndicatorsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_private_list_and_background_detail_keep_inbound_badge_even_when_latest_message_is_outgoing(): void
    {
        $viewer = User::factory()->create();
        $partner = User::factory()->create();
        $conversation = $this->conversation($viewer, $partner);
        $incoming = $conversation->messages()->create(['sender_id' => $partner->id, 'body' => 'Incoming unread']);
        $outgoing = $conversation->messages()->create(['sender_id' => $viewer->id, 'body' => 'My later message']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/chats')->assertOk()->assertJsonPath('data.conversations.0.unread_count', 1)
            ->assertJsonPath('data.conversations.0.latest_message.id', $outgoing->id);
        $this->getJson("/api/v1/chats/{$conversation->id}?mark_read=0")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson("/api/v1/chats/users/{$partner->id}?mark_read=0")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNull($incoming->fresh()->read_at);
        $this->getJson("/api/v1/chats/{$conversation->id}")->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($incoming->fresh()->read_at);
        $this->assertNull($outgoing->fresh()->read_at);
    }

    public function test_global_unread_total_includes_owned_business_community_and_visitor_chats_but_only_received_messages(): void
    {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $business = $this->page($owner, 'business');
        $community = $this->page($owner, 'community');
        $guest = $this->pageConversation($business, null);
        $guestIncoming = $guest->messages()->create(['sender_id' => null, 'sender_as_page' => false, 'body' => 'Guest question']);
        $ownReply = $guest->messages()->create(['sender_id' => $owner->id, 'sender_as_page' => true, 'body' => 'Own reply']);
        $member = $this->pageConversation($business, $visitor);
        $member->messages()->create(['sender_id' => $visitor->id, 'sender_as_page' => false, 'body' => 'Member question']);
        $group = $this->pageConversation($community, $visitor);
        $group->messages()->create(['sender_id' => $visitor->id, 'sender_as_page' => false, 'body' => 'Community question']);
        $otherBusiness = $this->page($visitor, 'business');
        $asVisitor = $this->pageConversation($otherBusiness, $owner);
        $asVisitor->messages()->create(['sender_id' => $visitor->id, 'sender_as_page' => true, 'body' => 'Reply to owner as visitor']);
        $this->assertSame(4, app(PayloadService::class)->unreadMessageCount($owner));
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/pages/{$business->id}/chats")->assertOk()
            ->assertJsonPath('data.conversations.0.unread_count', 1)->assertJsonPath('data.conversations.1.unread_count', 1);
        $this->getJson("/api/v1/page-chats/{$guest->id}?mark_read=0")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNull($guestIncoming->fresh()->read_at);
        $this->getJson('/api/v1/page-chats')->assertOk()->assertJsonPath('data.unread_count', 4);
        $this->patchJson("/api/v1/page-chats/{$guest->id}/read")->assertOk()->assertJsonPath('data.unread_count', 3);
        $this->assertNotNull($guestIncoming->fresh()->read_at);
        $this->assertNull($ownReply->fresh()->read_at);
        $otherBusiness->update(['is_unclaimed' => true]);
        $this->assertSame(2, app(PayloadService::class)->unreadMessageCount($owner));
    }

    public function test_support_background_polls_preserve_member_guest_and_admin_unread_until_opened(): void
    {
        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        $member = User::factory()->create();
        $account = $this->conversation($member, $admin, true);
        $memberMessage = $account->messages()->create(['sender_id' => $member->id, 'body' => 'Member needs help']);
        $adminMessage = $account->messages()->create(['sender_id' => $admin->id, 'body' => 'Member reply']);
        $token = str_repeat('a', 43);
        $guest = GuestSupportConversation::query()->create(['name' => 'Guest', 'locale' => 'en', 'token_hash' => hash('sha256', $token), 'last_message_at' => now()]);
        $guestMessage = $guest->messages()->create(['sender_type' => 'guest', 'body' => 'Guest needs help']);
        $guestReply = $guest->messages()->create(['sender_type' => 'admin', 'sender_user_id' => $admin->id, 'body' => 'Guest reply']);
        $claimed = GuestSupportConversation::query()->create(['name' => 'Claimed', 'locale' => 'en', 'token_hash' => hash('sha256', str_repeat('b', 43)), 'last_message_at' => now(), 'claimed_at' => now()]);
        $claimed->messages()->create(['sender_type' => 'guest', 'body' => 'Old claimed conversation']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/support-chats')->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->assertSame(2, app(PayloadService::class)->unreadMessageCount($admin));
        $this->getJson("/api/v1/admin/support-chats/account/{$account->id}?mark_read=0")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson("/api/v1/admin/support-chats/guest/{$guest->id}?mark_read=0")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNull($memberMessage->fresh()->read_at);
        $this->assertNull($guestMessage->fresh()->read_at);
        $this->getJson("/api/v1/admin/support-chats/account/{$account->id}")->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson("/api/v1/admin/support-chats/guest/{$guest->id}")->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertSame(0, app(PayloadService::class)->unreadMessageCount($admin));
        $this->assertNull($adminMessage->fresh()->read_at);
        $this->assertNull($guestReply->fresh()->read_at);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/chats/support?mark_read=0')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/v1/chats/support')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $token);
        $this->getJson('/api/v1/guest-support?mark_read=0')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNull($guestReply->fresh()->read_at);
        $this->getJson('/api/v1/guest-support')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($guestReply->fresh()->read_at);
    }

    public function test_guest_page_background_poll_does_not_mark_business_reply_as_read(): void
    {
        $owner = User::factory()->create();
        $page = $this->page($owner, 'business');
        $token = str_repeat('c', 43);
        $conversation = $this->pageConversation($page, null);
        $conversation->forceFill(['guest_token_hash' => hash('sha256', $token)])->save();
        $incoming = $conversation->messages()->create(['sender_id' => $owner->id, 'sender_as_page' => true, 'body' => 'Business reply']);
        $this->withHeader(GuestPageChatController::TOKEN_HEADER, $token);
        $this->getJson("/api/v1/pages/{$page->id}/guest-chat?mark_read=0")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNull($incoming->fresh()->read_at);
        $this->getJson("/api/v1/pages/{$page->id}/guest-chat")->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($incoming->fresh()->read_at);
    }

    private function conversation(User $first, User $second, bool $support = false): Conversation
    {
        return Conversation::query()->create(['user_one_id' => min($first->id, $second->id), 'user_two_id' => max($first->id, $second->id),
            'started_by_user_id' => $first->id, 'is_support' => $support, 'last_message_at' => now()]);
    }

    private function page(User $owner, string $type): Page
    {
        return Page::query()->create(['user_id' => $owner->id, 'type' => $type, 'name' => 'Unread Test '.$type, 'is_unclaimed' => false]);
    }

    private function pageConversation(Page $page, ?User $visitor): PageConversation
    {
        return PageConversation::query()->create(['page_id' => $page->id, 'visitor_id' => $visitor?->id, 'last_message_at' => now()]);
    }
}
