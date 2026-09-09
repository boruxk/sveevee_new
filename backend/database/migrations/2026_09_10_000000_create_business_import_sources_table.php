<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_import_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('source_id', 255);
            // Keep the identity after deletion so an old snapshot cannot recreate a removed page.
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('url', 2048);
            $table->json('metadata');
            $table->timestamps();
            $table->unique(['provider', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_import_sources');
    }
};
