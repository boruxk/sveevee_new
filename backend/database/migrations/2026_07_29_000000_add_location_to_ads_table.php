<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('status')->index();
            $table->string('neighborhood')->nullable()->after('city')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropIndex(['city']);
            $table->dropIndex(['neighborhood']);
            $table->dropColumn(['city', 'neighborhood']);
        });
    }
};
