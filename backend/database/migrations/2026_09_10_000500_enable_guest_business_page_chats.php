<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_conversations', function (Blueprint $table) {
            $table->foreignId('visitor_id')->nullable()->change();
            $table->string('guest_token_hash', 64)->nullable()->unique();
            $table->foreignId('guest_claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guest_claimed_conversation_id')->nullable()->constrained('page_conversations')->nullOnDelete();
            $table->timestamp('guest_claimed_at')->nullable();
        });

        Schema::table('page_chat_messages', function (Blueprint $table) {
            $table->foreignId('sender_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back must never silently discard a business's guest history.
        if (DB::table('page_conversations')->whereNull('visitor_id')->exists()
            || DB::table('page_chat_messages')->whereNull('sender_id')->exists()) {
            throw new RuntimeException('Guest page chats must be migrated before reverting nullable chat participants.');
        }

        Schema::table('page_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_claimed_by_user_id');
            $table->dropConstrainedForeignId('guest_claimed_conversation_id');
            $table->dropUnique(['guest_token_hash']);
            $table->dropColumn(['guest_token_hash', 'guest_claimed_at']);
            $table->foreignId('visitor_id')->nullable(false)->change();
        });

        Schema::table('page_chat_messages', function (Blueprint $table) {
            $table->foreignId('sender_id')->nullable(false)->change();
        });
    }
};
