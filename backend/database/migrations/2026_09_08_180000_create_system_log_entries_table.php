<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_log_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source', 64)->index();
            $table->string('type', 64)->index();
            $table->string('status', 24)->index();
            $table->string('external_id', 128);
            $table->string('actor_type', 48)->nullable();
            $table->string('actor_identifier', 128)->nullable();
            $table->char('payload_hash', 64);
            $table->json('data');
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->unique(
                ['source', 'type', 'external_id'],
                'system_log_entries_source_type_external_unique'
            );
            $table->index(
                ['source', 'type', 'occurred_at'],
                'system_log_entries_source_type_occurred_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_log_entries');
    }
};
