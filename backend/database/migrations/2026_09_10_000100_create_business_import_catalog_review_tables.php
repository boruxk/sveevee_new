<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_import_cities', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->char('value_hash', 64);
            $table->string('raw_value', 120);
            $table->string('first_source_id', 255);
            $table->foreignId('example_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('mapped_city', 120)->nullable();
            $table->unique(['provider', 'value_hash']);
        });
        Schema::create('business_import_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->char('value_hash', 64);
            $table->string('raw_value', 255);
            $table->string('label', 500);
            $table->string('first_source_id', 255);
            $table->foreignId('example_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('mapped_category_key', 120)->nullable();
            $table->unique(['provider', 'value_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_import_categories');
        Schema::dropIfExists('business_import_cities');
    }
};
