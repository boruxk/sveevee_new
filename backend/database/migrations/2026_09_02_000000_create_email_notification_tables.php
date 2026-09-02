<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_profiles', 'email_chat_notifications')) {
            Schema::table('user_profiles', function (Blueprint $table): void {
                $table->boolean('email_chat_notifications')->default(false)->after('user_type');
            });
        }

        if (! Schema::hasTable('email_deliveries')) {
            Schema::create('email_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('chat_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
                $table->string('kind', 40)->index();
                $table->string('recipient_email')->index();
                $table->string('bounce_token', 64)->unique();
                $table->string('status', 24)->default('queued')->index();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->text('failure_reason')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('bounced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('email_suppressions')) {
            Schema::create('email_suppressions', function (Blueprint $table): void {
                $table->id();
                $table->string('email')->unique();
                $table->string('reason', 64);
                $table->text('diagnostic')->nullable();
                $table->foreignId('source_delivery_id')->nullable()->constrained('email_deliveries')->nullOnDelete();
                $table->timestamp('suppressed_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_email_notification_states')) {
            Schema::create('chat_email_notification_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('pending_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
                $table->string('pending_token', 64)->nullable()->unique();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['conversation_id', 'recipient_id'],
                    'chat_email_states_conversation_recipient_unique'
                );
            });
        } elseif (! Schema::hasIndex(
            'chat_email_notification_states',
            'chat_email_states_conversation_recipient_unique'
        )) {
            Schema::table('chat_email_notification_states', function (Blueprint $table): void {
                $table->unique(
                    ['conversation_id', 'recipient_id'],
                    'chat_email_states_conversation_recipient_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_email_notification_states');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_deliveries');

        if (Schema::hasColumn('user_profiles', 'email_chat_notifications')) {
            Schema::table('user_profiles', function (Blueprint $table): void {
                $table->dropColumn('email_chat_notifications');
            });
        }
    }
};
