<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_claim_requests', function (Blueprint $table): void {
            $table->boolean('replace_existing')->default(false)->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('page_claim_requests', function (Blueprint $table): void {
            $table->dropColumn('replace_existing');
        });
    }
};
