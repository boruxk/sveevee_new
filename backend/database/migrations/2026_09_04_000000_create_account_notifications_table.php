<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 64)->index();
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->uuid('notification_id')->nullable()->after('chat_message_id');
            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->dropForeign(['notification_id']);
            $table->dropColumn('notification_id');
        });

        Schema::dropIfExists('notifications');
    }
};
