<?php

namespace Tests\Feature;

use App\Models\GuestSupportConversation;
use App\Models\GuestSupportMessage;
use App\Models\User;
use App\Services\GuestSupportService;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestSupportReplyLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_send_five_messages_and_only_an_actual_support_reply_resets_the_limit(): void
    {
        $this->freezeTime();
        $started = $this->start()->assertCreated()
            ->assertJsonPath('data.conversation.composer_state.limit', 5)
            ->assertJsonPath('data.conversation.composer_state.remaining', 4);
        $id = $started->json('data.conversation.id');
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        for ($number = 2; $number <= 5; $number++) {
            $this->postJson('/api/v1/guest-support/messages', ['body' => 'Another detail '.$number])->assertCreated()
                ->assertJsonPath('data.composer_state.remaining', 5 - $number)
                ->assertJsonPath('data.composer_state.can_send', $number < 5);
        }
        $this->assertWaiting();
        $this->assertSame(5, GuestSupportMessage::query()->where('is_automatic', false)->count());

        Sanctum::actingAs($this->supportAdmin());
        $this->getJson('/api/v1/admin/support-chats/guest/'.$id)->assertOk()
            ->assertJsonPath('data.composer_state.can_send', true)
            ->assertJsonPath('data.unread_count', 0);
        $this->assertWaiting();
        $this->getJson('/api/v1/guest-support')->assertOk()
            ->assertJsonPath('data.composer_state.reason', 'support_pending_reply')
            ->assertJsonPath('data.composer_state.remaining', 0);

        // Support replies may include helpful links. All timestamps deliberately
        // match: ID is the chronological tiebreaker, not read_at or wall-clock time.
        $this->postJson('/api/v1/admin/support-chats/guest/'.$id.'/messages', ['body' => 'Help: https://sveevee.co.il/help'])
            ->assertCreated()->assertJsonPath('data.composer_state.can_send', true);
        $this->getJson('/api/v1/guest-support')->assertOk()
            ->assertJsonPath('data.composer_state.can_send', true)
            ->assertJsonPath('data.composer_state.remaining', 5);
        for ($number = 1; $number <= 5; $number++) {
            $this->postJson('/api/v1/guest-support/messages', ['body' => 'A follow-up '.$number])->assertCreated()
                ->assertJsonPath('data.composer_state.remaining', 5 - $number);
        }
        $this->assertWaiting();
        $this->assertSame(11, GuestSupportMessage::query()->where('is_automatic', false)->count());
    }

    public function test_configured_limit_is_enforced_and_setting_changes_apply_to_existing_conversations(): void
    {
        app(SystemSettingsService::class)->updateSection('chat', ['support_messages_before_reply' => 2], null);
        $started = $this->start()->assertCreated()
            ->assertJsonPath('data.conversation.composer_state.limit', 2)
            ->assertJsonPath('data.conversation.composer_state.remaining', 1);
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'A second detail'])->assertCreated()
            ->assertJsonPath('data.composer_state.can_send', false);
        $this->assertWaiting();
        app(SystemSettingsService::class)->updateSection('chat', ['support_messages_before_reply' => 3], null);
        $this->getJson('/api/v1/guest-support')->assertOk()
            ->assertJsonPath('data.composer_state.remaining', 1)
            ->assertJsonPath('data.composer_state.can_send', true);
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'The newly allowed detail'])->assertCreated();
        $this->assertWaiting();
    }

    public function test_old_cached_settings_expose_the_default_without_resetting_other_values(): void
    {
        $settings = app(SystemSettingsService::class)->defaults();
        unset($settings['chat']['support_messages_before_reply']);
        $settings['chat']['messages_per_minute'] = 18;
        $settings['platform']['popular_topic_keys'] = [];
        Cache::forever(SystemSettingsService::CACHE_KEY, $settings);
        Sanctum::actingAs($this->supportAdmin());
        $this->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonPath('data.settings.chat.support_messages_before_reply', 5)
            ->assertJsonPath('data.settings.chat.messages_per_minute', 18)
            ->assertJsonPath('data.settings.platform.popular_topic_keys', []);
    }

    public function test_admin_can_configure_the_limit_and_older_clients_omitting_it_preserve_its_value(): void
    {
        Sanctum::actingAs($this->supportAdmin());
        $fields = ['new_recipients_per_day' => 10, 'messages_per_minute' => 30, 'guest_retention_days' => 90];
        $this->patchJson('/api/v1/admin/settings/chat', [...$fields, 'support_messages_before_reply' => 2])->assertOk()
            ->assertJsonPath('data.settings.support_messages_before_reply', 2);
        $this->patchJson('/api/v1/admin/settings/chat', [...$fields, 'guest_retention_days' => 120])->assertOk()
            ->assertJsonPath('data.settings.support_messages_before_reply', 2);
        app(SystemSettingsService::class)->clearCache();
        $this->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonPath('data.settings.chat.support_messages_before_reply', 2);
        foreach ([null, 0, -1, 101, 1.5, 'unlimited'] as $invalid) {
            $this->patchJson('/api/v1/admin/settings/chat', [...$fields, 'support_messages_before_reply' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('support_messages_before_reply');
        }
        $this->assertSame(2, app(SystemSettingsService::class)->integer('chat.support_messages_before_reply', 5));
    }

    public function test_guest_support_rejects_links_in_name_and_messages_but_accepts_the_contact_email(): void
    {
        $this->postJson('/api/v1/guest-support', ['name' => 'https://example.com', 'locale' => 'en', 'body' => 'Hello'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/guest-support', ['name' => 'Visitor', 'locale' => 'en', 'body' => 'See https://example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, GuestSupportConversation::query()->count());
        $started = $this->start(['email' => 'visitor@example.com'])->assertCreated();
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'See www.example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->getJson('/api/v1/guest-support')->assertOk()->assertJsonPath('data.composer_state.remaining', 4);
        $this->assertSame(1, GuestSupportMessage::query()->where('is_automatic', false)->count());
    }

    public function test_non_text_guest_fields_are_validation_errors_without_consuming_the_message_budget(): void
    {
        $this->start(['name' => ['Visitor']])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->start(['body' => ['My question']])->assertUnprocessable()->assertJsonValidationErrors('body');
        $started = $this->start()->assertCreated();
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        $this->postJson('/api/v1/guest-support/messages', ['body' => ['Another detail']])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->getJson('/api/v1/guest-support')->assertOk()->assertJsonPath('data.composer_state.remaining', 4);
        $this->assertSame(1, GuestSupportMessage::query()->where('is_automatic', false)->count());
    }

    public function test_claiming_guest_history_preserves_waiting_state_and_invalidates_guest_send_paths(): void
    {
        app(SystemSettingsService::class)->updateSection('chat', ['support_messages_before_reply' => 1], null);
        $started = $this->start()->assertCreated()->assertJsonPath('data.conversation.composer_state.can_send', false);
        $id = $started->json('data.conversation.id');
        $this->withHeader(GuestSupportService::TOKEN_HEADER, $started->json('data.token'));
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/guest-support/claim')->assertOk()
            ->assertJsonPath('data.composer_state.reason', 'support_pending_reply')
            ->assertJsonPath('data.composer_state.can_send', false)
            ->assertJsonPath('data.composer_state.remaining', 0);
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'Stale guest send'])->assertNotFound();
        Sanctum::actingAs($this->supportAdmin());
        $this->postJson('/api/v1/admin/support-chats/guest/'.$id.'/messages', ['body' => 'Stale guest reply'])->assertNotFound();
        $this->assertSame(1, GuestSupportMessage::query()->where('is_automatic', false)->count());
    }

    private function start(array $overrides = [])
    {
        return $this->postJson('/api/v1/guest-support', ['name' => 'Visitor', 'locale' => 'en', 'body' => 'My question', ...$overrides]);
    }

    private function assertWaiting(): void
    {
        $this->postJson('/api/v1/guest-support/messages', ['body' => 'One message too many'])->assertStatus(409)
            ->assertJsonPath('errors.reason', 'support_pending_reply');
    }

    private function supportAdmin(): User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
    }
}
