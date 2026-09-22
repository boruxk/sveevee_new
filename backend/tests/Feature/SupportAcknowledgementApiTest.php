<?php

namespace Tests\Feature;

use App\Jobs\SendUnreadChatEmail;
use App\Models\ChatMessage;
use App\Models\GuestSupportMessage;
use App\Models\Page;
use App\Models\User;
use App\Services\GuestSupportService;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupportAcknowledgementApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH = 'Thank you for your message. A member of the sveevee support team will reply as soon as possible.';

    public function test_account_acknowledgement_is_immediate_once_per_wait_and_does_not_read_or_reset_the_limit(): void
    {
        Mail::fake();
        Queue::fake();
        app(SystemSettingsService::class)->updateSection('chat', ['support_messages_before_reply' => 2], null);
        $member = User::factory()->create(['locale' => 'en']);
        $admin = $this->supportAdmin();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/chats/support')->assertOk()->assertJsonCount(0, 'data.messages');
        $this->postJson('/api/v1/chats/support/messages', ['body' => ''])->assertUnprocessable();
        $this->assertSame(0, ChatMessage::query()->count());

        $sent = $this->postJson('/api/v1/chats/support/messages', [
            'body' => 'My question', 'is_automatic' => true, 'sender_id' => $admin->id, 'read_at' => now()->toISOString(),
        ])->assertCreated()->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.is_automatic', false)
            ->assertJsonPath('data.messages.0.sender_id', $member->id)
            ->assertJsonPath('data.messages.0.read_at', null)
            ->assertJsonPath('data.messages.1.is_automatic', true)
            ->assertJsonPath('data.messages.1.sender_id', $admin->id)
            ->assertJsonPath('data.messages.1.body', self::ENGLISH)
            ->assertJsonPath('data.composer_state.remaining', 1);
        $id = $sent->json('data.id');
        $this->postJson('/api/v1/chats/'.$id.'/messages', ['body' => 'More details', 'is_automatic' => true])
            ->assertCreated()->assertJsonCount(3, 'data.messages')->assertJsonPath('data.composer_state.remaining', 0);
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Too many'])->assertStatus(409);
        $this->assertSame(1, ChatMessage::query()->where('is_automatic', true)->count());
        $this->assertSame(2, ChatMessage::query()->where('sender_id', $member->id)->whereNull('read_at')->count());

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/support-chats')->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->getJson('/api/v1/admin/support-chats/account/'.$id)->assertOk();
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Read is not a reply'])->assertStatus(409);
        $this->getJson('/api/v1/chats/support')->assertOk()->assertJsonPath('data.composer_state.remaining', 0);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/support-chats/account/'.$id.'/messages', [
            'body' => 'A human answer', 'is_automatic' => true,
        ])->assertCreated()->assertJsonPath('data.messages.3.is_automatic', false);
        $this->assertSame(1, ChatMessage::query()->where('is_automatic', true)->count());
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'Follow-up'])->assertCreated()
            ->assertJsonCount(6, 'data.messages')->assertJsonPath('data.messages.5.is_automatic', true)
            ->assertJsonPath('data.composer_state.remaining', 1);
        $this->assertSame(2, ChatMessage::query()->where('is_automatic', true)->count());
        Queue::assertNotPushed(SendUnreadChatEmail::class);
        Mail::assertNothingOutgoing();
    }

    public function test_guest_acknowledgement_preserves_unread_and_waits_for_human_reply(): void
    {
        Mail::fake();
        Queue::fake();
        app(SystemSettingsService::class)->updateSection('chat', ['support_messages_before_reply' => 1], null);
        $this->postJson('/api/v1/guest-support', ['name' => 'Visitor', 'locale' => 'en', 'body' => 'https://example.com'])
            ->assertUnprocessable();
        $this->assertSame(0, GuestSupportMessage::query()->count());
        $started = $this->postJson('/api/v1/guest-support', [
            'name' => 'Visitor', 'locale' => 'en', 'body' => 'My question', 'is_automatic' => true, 'sender_type' => 'admin',
        ])->assertCreated()->assertJsonCount(2, 'data.conversation.messages')
            ->assertJsonPath('data.conversation.messages.0.is_automatic', false)
            ->assertJsonPath('data.conversation.messages.0.sender_type', 'guest')
            ->assertJsonPath('data.conversation.messages.0.read_at', null)
            ->assertJsonPath('data.conversation.messages.1.is_automatic', true)
            ->assertJsonPath('data.conversation.messages.1.body', self::ENGLISH)
            ->assertJsonPath('data.conversation.composer_state.remaining', 0);
        $id = $started->json('data.conversation.id');
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        $this->getJson('/api/v1/guest-support')->assertOk()->assertJsonCount(2, 'data.messages');
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'Too many'])->assertStatus(409);
        $this->assertSame(1, GuestSupportMessage::query()->where('sender_type', 'guest')->whereNull('read_at')->count());

        Sanctum::actingAs($this->supportAdmin());
        $this->getJson('/api/v1/admin/support-chats')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/v1/admin/support-chats/guest/'.$id)->assertOk();
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'Read is not a reply'])->assertStatus(409);
        $this->postJson('/api/v1/admin/support-chats/guest/'.$id.'/messages', [
            'body' => 'A human answer', 'is_automatic' => true,
        ])->assertCreated()->assertJsonPath('data.messages.2.is_automatic', false);
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'www.example.com'])->assertUnprocessable();
        $this->assertSame(1, GuestSupportMessage::query()->where('is_automatic', true)->count());
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'Follow-up', 'is_automatic' => true])
            ->assertCreated()->assertJsonCount(5, 'data.messages')
            ->assertJsonPath('data.messages.3.is_automatic', false)
            ->assertJsonPath('data.messages.3.read_at', null)
            ->assertJsonPath('data.messages.4.is_automatic', true)
            ->assertJsonPath('data.composer_state.remaining', 0);
        $this->assertSame(2, GuestSupportMessage::query()->where('is_automatic', true)->count());
        Queue::assertNotPushed(SendUnreadChatEmail::class);
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('localizedBodies')]
    public function test_both_support_flows_store_the_visitors_language(string $locale, string $body): void
    {
        $started = $this->postJson('/api/v1/guest-support', [
            'name' => 'Visitor', 'locale' => $locale, 'body' => 'My question',
        ])->assertCreated()->assertJsonPath('data.conversation.messages.1.body', $body);
        $this->assertDatabaseHas('guest_support_messages', [
            'guest_support_conversation_id' => $started->json('data.conversation.id'),
            'body' => $body, 'is_automatic' => true,
        ]);
        Sanctum::actingAs(User::factory()->create(['locale' => $locale]));
        $sent = $this->postJson('/api/v1/chats/support/messages', ['body' => 'My question'])
            ->assertCreated()->assertJsonPath('data.messages.1.body', $body);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $sent->json('data.id'), 'body' => $body, 'is_automatic' => true,
        ]);
    }

    public static function localizedBodies(): array
    {
        return [
            'English' => ['en', self::ENGLISH],
            'Hebrew' => ['he', 'תודה על הודעתך. אחד מאנשי צוות התמיכה של sveevee יענה בהקדם האפשרי.'],
            'Russian' => ['ru', 'Спасибо за сообщение. Сотрудник службы поддержки sveevee ответит вам как можно скорее.'],
            'French' => ['fr', 'Merci pour votre message. Un membre de l’équipe d’assistance sveevee vous répondra dès que possible.'],
        ];
    }

    public function test_claim_keeps_automatic_history_once_without_resetting_its_waiting_period(): void
    {
        $this->freezeTime();
        $started = $this->postJson('/api/v1/guest-support', [
            'name' => 'Visitor', 'locale' => 'en', 'body' => 'Before registration',
        ])->assertCreated();
        $originalTime = $started->json('data.conversation.messages.1.created_at');
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        Sanctum::actingAs(User::factory()->create(['locale' => 'en']));
        $claimed = $this->postJson('/api/v1/guest-support/claim')->assertOk()
            ->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.is_automatic', true)
            ->assertJsonPath('data.messages.1.created_at', $originalTime)
            ->assertJsonPath('data.composer_state.remaining', 4);
        $this->postJson('/api/v1/guest-support/claim')->assertOk()->assertJsonCount(2, 'data.messages');
        $this->postJson('/api/v1/chats/'.$claimed->json('data.id').'/messages', ['body' => 'After registration'])
            ->assertCreated()->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.2.is_automatic', false)
            ->assertJsonPath('data.composer_state.remaining', 3);
        $this->assertSame(1, ChatMessage::query()->where('is_automatic', true)->count());
    }

    public function test_claimed_old_automatic_message_does_not_hide_a_newer_human_reply(): void
    {
        $this->travelTo(now()->subDay());
        $guest = $this->postJson('/api/v1/guest-support', [
            'name' => 'Visitor', 'locale' => 'en', 'body' => 'An old question',
        ])->assertCreated();
        $this->travelBack();
        $member = User::factory()->create(['locale' => 'en']);
        Sanctum::actingAs($member);
        $account = $this->postJson('/api/v1/chats/support/messages', ['body' => 'A current question'])->assertCreated();
        $id = $account->json('data.id');
        Sanctum::actingAs($this->supportAdmin());
        $this->postJson('/api/v1/admin/support-chats/account/'.$id.'/messages', ['body' => 'A current answer'])->assertCreated();
        Sanctum::actingAs($member);
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $guest->json('data.token'))
            ->postJson('/api/v1/guest-support/claim')->assertOk()
            ->assertJsonPath('data.messages.0.body', 'An old question')
            ->assertJsonPath('data.messages.4.body', 'A current answer')
            ->assertJsonPath('data.composer_state.remaining', 5);
        $this->postJson('/api/v1/chats/support/messages', ['body' => 'A follow-up'])->assertCreated()
            ->assertJsonCount(7, 'data.messages')->assertJsonPath('data.messages.6.is_automatic', true)
            ->assertJsonPath('data.composer_state.remaining', 4);
        $this->assertSame(3, ChatMessage::query()->where('is_automatic', true)->count());
    }

    public function test_private_and_business_chats_never_receive_support_acknowledgements(): void
    {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $page = Page::query()->create(['user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Business']);
        $this->postJson('/api/v1/pages/'.$page->id.'/guest-chat', ['body' => 'Are you open?'])
            ->assertCreated()->assertJsonCount(1, 'data.conversation.messages');
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/chats/users/'.$owner->id.'/messages', ['body' => 'Hello'])
            ->assertCreated()->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.is_automatic', false);
        $this->postJson('/api/v1/pages/'.$page->id.'/chat/messages', ['body' => 'Are you open?'])
            ->assertCreated()->assertJsonCount(1, 'data.messages');
        $this->assertSame(0, ChatMessage::query()->where('is_automatic', true)->count());
    }

    private function supportAdmin(): User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
    }
}
