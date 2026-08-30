<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn(['source_url', 'source_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->string('source_url', 2048)->nullable()->after('setup');
            $table->date('source_checked_at')->nullable()->after('source_url');
        });
    }
};
