<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->index()->after('banned_reason');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_one_cleared_message_id')->nullable()->after('last_message_at');
            $table->unsignedBigInteger('user_two_cleared_message_id')->nullable()->after('user_one_cleared_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn([
                'user_one_cleared_message_id',
                'user_two_cleared_message_id',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
