<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestPageChatMigrationTest extends TestCase
{
    public function test_schema_upgrade_preserves_existing_account_history_and_foreign_keys(): void
    {
        // Use an isolated in-memory connection: SQLite table rebuilds cannot disable
        // cascading foreign keys inside RefreshDatabase's wrapping transaction.
        config(['database.connections.guest_chat_migration' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('guest_chat_migration');
        $this->artisan('migrate', ['--database' => 'guest_chat_migration', '--force' => true])->assertExitCode(0);

        $migration = require database_path('migrations/2026_09_10_000500_enable_guest_business_page_chats.php');
        $migration->down();
        $page = Page::query()->create([
            'user_id' => User::factory()->create()->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Existing Business',
        ]);
        $visitor = User::factory()->create();
        $id = DB::table('page_conversations')->insertGetId([
            'page_id' => $page->id, 'visitor_id' => $visitor->id,
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $messageId = DB::table('page_chat_messages')->insertGetId([
            'page_conversation_id' => $id, 'sender_id' => $visitor->id, 'body' => 'Existing history.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        Sanctum::actingAs($page->user);
        $this->getJson('/api/v1/page-chats/'.$id)->assertOk()->assertJsonPath('data.messages.0.id', $messageId)
            ->assertJsonPath('data.messages.0.body', 'Existing history.')->assertJsonPath('data.is_guest', false);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
