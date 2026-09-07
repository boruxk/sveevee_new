<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->string('normalized_email')->nullable()->after('normalized_phone')->index();
        });

        DB::table('pages')
            ->select(['id', 'contact_email'])
            ->whereNotNull('contact_email')
            ->orderBy('id')
            ->chunkById(100, function ($pages): void {
                foreach ($pages as $page) {
                    DB::table('page_identity_keys')
                        ->where('page_id', $page->id)
                        ->update([
                            'normalized_email' => mb_strtolower(trim((string) $page->contact_email), 'UTF-8') ?: null,
                        ]);
                }
            });

        Schema::create('business_import_clients', function (Blueprint $table): void {
            $table->uuid('oauth_client_id')->primary();
            $table->string('name');
            $table->json('allowed_scopes');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->foreign('oauth_client_id')->references('id')->on('oauth_clients')->cascadeOnDelete();
        });

        Schema::create('business_import_pages', function (Blueprint $table): void {
            $table->foreignId('page_id')->primary()->constrained('pages')->cascadeOnDelete();
            $table->uuid('created_by_oauth_client_id')->nullable();
            $table->uuid('last_updated_by_oauth_client_id')->nullable();
            $table->char('last_payload_hash', 64);
            $table->timestamps();

            $table->foreign('created_by_oauth_client_id')
                ->references('oauth_client_id')->on('business_import_clients')->nullOnDelete();
            $table->foreign('last_updated_by_oauth_client_id')
                ->references('oauth_client_id')->on('business_import_clients')->nullOnDelete();
        });

        Schema::create('business_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('oauth_client_id');
            $table->uuid('client_import_id');
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('processing')->index();
            $table->unsignedSmallInteger('input_count')->default(0);
            $table->unsignedSmallInteger('created_count')->default(0);
            $table->unsignedSmallInteger('updated_count')->default(0);
            $table->unsignedSmallInteger('duplicate_count')->default(0);
            $table->unsignedSmallInteger('invalid_count')->default(0);
            $table->unsignedSmallInteger('conflict_count')->default(0);
            $table->json('result')->nullable();
            $table->timestamps();

            $table->foreign('oauth_client_id')
                ->references('oauth_client_id')->on('business_import_clients')->cascadeOnDelete();
            $table->unique(['oauth_client_id', 'client_import_id'], 'business_import_batches_client_import_unique');
        });

        Schema::create('business_import_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('oauth_client_id');
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('processing')->index();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->foreign('oauth_client_id')
                ->references('oauth_client_id')->on('business_import_clients')->cascadeOnDelete();
            $table->unique(
                ['oauth_client_id', 'idempotency_key'],
                'business_import_idempotency_client_key_unique'
            );
        });

        Schema::create('business_import_api_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->uuid('oauth_client_id')->nullable();
            $table->char('oauth_access_token_id', 80)->nullable();
            $table->string('method', 10);
            $table->string('route_name')->nullable();
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status_code');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('payload_bytes')->default(0);
            $table->unsignedSmallInteger('item_count')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['oauth_client_id', 'created_at'], 'business_import_logs_client_created_index');
            $table->index(['status_code', 'created_at'], 'business_import_logs_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_import_api_logs');
        Schema::dropIfExists('business_import_idempotency_keys');
        Schema::dropIfExists('business_import_batches');
        Schema::dropIfExists('business_import_pages');
        Schema::dropIfExists('business_import_clients');

        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->dropColumn('normalized_email');
        });
    }
};
