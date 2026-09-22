<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['page_products', 'page_services'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('community_hidden_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['page_products', 'page_services'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('community_hidden_at');
            });
        }
    }
};
