<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_page_leads', function (Blueprint $table): void {
            $table->string('source', 64)
                ->default('leads_page_001')
                ->after('id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('business_page_leads', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
