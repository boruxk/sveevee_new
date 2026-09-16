<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_import_closures', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('source_id', 255);
            $table->date('date_closed');
            $table->string('status', 32)->index();
            $table->string('reason', 80)->nullable();
            // Audit identities survive deletion of the page and OAuth client.
            $table->unsignedBigInteger('removed_page_id')->nullable();
            $table->json('page_snapshot')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['provider', 'source_id'], 'business_closure_source_unique');
        });
        Schema::create('business_import_closure_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->constrained('business_import_closures')->cascadeOnDelete();
            $table->string('snapshot_id', 120);
            $table->date('release');
            $table->string('actor', 255);
            $table->char('evidence_hash', 64);
            $table->json('evidence');
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['closure_id', 'snapshot_id'], 'business_closure_snapshot_unique');
        });
        Schema::create('business_import_source_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('source_id', 255);
            $table->foreignId('closure_id')->constrained('business_import_closures')->restrictOnDelete();
            $table->unsignedBigInteger('removed_page_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['provider', 'source_id'], 'business_closed_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_import_source_tombstones');
        Schema::dropIfExists('business_import_closure_events');
        Schema::dropIfExists('business_import_closures');
    }
};
