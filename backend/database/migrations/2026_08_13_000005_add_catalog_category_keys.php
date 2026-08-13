<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pages', 'page_products', 'page_services', 'page_events'] as $tableName) {
            if (Schema::hasColumn($tableName, 'category_key')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('category_key', 120)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['page_events', 'page_services', 'page_products', 'pages'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'category_key')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_category_key_index');
                $table->dropColumn('category_key');
            });
        }
    }
};
