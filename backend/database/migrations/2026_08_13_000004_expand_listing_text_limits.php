<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropIndex('ads_title_type_index');
            $table->string('title', 1000)->change();
        });

        Schema::table('page_products', function (Blueprint $table): void {
            $table->string('name', 1000)->change();
        });

        Schema::table('page_services', function (Blueprint $table): void {
            $table->string('name', 1000)->change();
        });

        Schema::table('page_events', function (Blueprint $table): void {
            $table->string('name', 1000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->string('title')->change();
            $table->index(['title', 'type']);
        });

        Schema::table('page_products', function (Blueprint $table): void {
            $table->string('name')->change();
        });

        Schema::table('page_services', function (Blueprint $table): void {
            $table->string('name')->change();
        });

        Schema::table('page_events', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
