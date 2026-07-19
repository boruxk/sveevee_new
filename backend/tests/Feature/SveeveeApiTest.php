<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SveeveeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_admin_user_and_private_ad_without_prefilled_pages(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'admin@sveevee.local', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'user@sveevee.local', 'role' => 'user']);
        $this->assertDatabaseMissing('pages', ['type' => 'business']);
        $this->assertDatabaseMissing('pages', ['type' => 'community']);
        $this->assertDatabaseHas('ads', ['type' => 'private_ad', 'title' => 'Kids chair to give away']);
    }

    public function test_chat_requires_reply_before_second_message_to_same_user(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Hallo',
        ])->assertCreated();

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Noch eine Nachricht',
        ])->assertStatus(409)
            ->assertJsonPath('errors.reason', 'pending_reply');

        Sanctum::actingAs($recipient);

        $this->postJson("/api/v1/chats/users/{$sender->id}/messages", [
            'body' => 'Antwort',
        ])->assertCreated();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Danke',
        ])->assertCreated();
    }

    public function test_user_can_contact_only_ten_new_users_per_day(): void
    {
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);

        $recipients = User::factory()->count(11)->create();

        foreach ($recipients->take(10) as $recipient) {
            $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
                'body' => 'Hallo',
            ])->assertCreated();
        }

        $this->postJson("/api/v1/chats/users/{$recipients->last()->id}/messages", [
            'body' => 'Hallo Nummer 11',
        ])->assertStatus(429)
            ->assertJsonPath('errors.reason', 'daily_limit');
    }

    public function test_user_can_create_page_with_presence_details(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/pages/business', [
            'name' => 'Miri Studio',
            'public_description' => 'Local design help.',
            'contact_email' => 'hello@example.test',
            'phone' => '+972 50 111 2222',
            'address' => 'Herzl 10, Haifa',
            'palette_key' => 'sea-glass',
            'setup' => [
                'contact' => [
                    'tel' => '+972 50 111 2222',
                    'email' => 'hello@example.test',
                    'whatsapp' => '+972 50 111 2222',
                ],
                'address' => [
                    'street' => 'Herzl',
                    'number' => '10',
                    'city' => 'Haifa',
                ],
                'opening_hours' => [
                    ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '08:30', 'closes_at' => '16:00'],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.palette_key', 'sea-glass')
            ->assertJsonPath('data.contact.whatsapp', '+972 50 111 2222')
            ->assertJsonPath('data.address_details.street', 'Herzl')
            ->assertJsonPath('data.opening_hours.1.weekday', 'monday')
            ->assertJsonPath('data.opening_hours.1.opens_at', '08:30');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business_page.name', 'Miri Studio');
    }

    public function test_admin_can_ban_email_and_block_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'email' => 'blocked@example.test',
            'password' => 'password',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}/ban", [
            'reason' => 'Spam',
        ])->assertOk()
            ->assertJsonPath('data.banned_at', fn ($value) => filled($value));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.test',
            'password' => 'password',
        ])->assertStatus(403);
    }
}
