<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_support_conversations', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('name', 100);
            $table->string('email', 254)->nullable();
            $table->string('locale', 8)->default('en');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('claimed_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('guest_support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guest_support_conversation_id')
                ->constrained('guest_support_conversations', indexName: 'guest_support_messages_conversation_foreign')
                ->cascadeOnDelete();
            $table->string('sender_type', 16)->index();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['guest_support_conversation_id', 'created_at'],
                'guest_support_messages_conversation_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_support_messages');
        Schema::dropIfExists('guest_support_conversations');
    }
};
