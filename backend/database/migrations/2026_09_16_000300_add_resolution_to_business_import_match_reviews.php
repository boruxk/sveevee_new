<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_import_match_reviews', function (Blueprint $table): void {
            // Independent audit receipt survives later source metadata refreshes and page deletion.
            $table->json('resolution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_import_match_reviews', fn (Blueprint $table) => $table->dropColumn('resolution'));
    }
};
