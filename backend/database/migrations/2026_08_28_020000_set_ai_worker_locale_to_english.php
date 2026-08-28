<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const AI_LOGIN = 'spfksfmbvpt';

    public function up(): void
    {
        DB::table('users')
            ->where('login', self::AI_LOGIN)
            ->where('role', 'ai_worker')
            ->update([
                'locale' => 'en',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('login', self::AI_LOGIN)
            ->where('role', 'ai_worker')
            ->update([
                'locale' => 'he',
                'updated_at' => now(),
            ]);
    }
};
