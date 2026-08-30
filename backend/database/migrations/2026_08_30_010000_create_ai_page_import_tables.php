<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_work_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('page_defaults');
            $table->timestamps();
        });

        Schema::create('ai_page_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_import_id');
            $table->string('status', 24)->default('completed')->index();
            $table->unsignedSmallInteger('input_count')->default(0);
            $table->unsignedSmallInteger('created_count')->default(0);
            $table->unsignedSmallInteger('duplicate_count')->default(0);
            $table->unsignedSmallInteger('invalid_count')->default(0);
            $table->json('created_page_ids')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['created_by_user_id', 'client_import_id'], 'ai_page_imports_worker_client_unique');
        });

        Schema::create('ai_page_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_page_import_id')->constrained('ai_page_imports')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('row_key', 64);
            $table->json('payload');
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ai_page_import_id', 'row_key'], 'ai_page_import_rows_import_key_unique');
        });

        Schema::create('page_identity_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->unique()->constrained('pages')->cascadeOnDelete();
            $table->string('type', 24)->index();
            $table->string('category_key', 120)->nullable()->index();
            $table->string('normalized_name')->index();
            $table->string('normalized_city', 120)->nullable()->index();
            $table->string('normalized_neighborhood', 120)->nullable();
            $table->string('normalized_phone', 32)->nullable()->index();
            $table->string('normalized_website', 255)->nullable()->index();
            $table->string('normalized_address', 600)->nullable();
            $table->char('identity_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_identity_keys');
        Schema::dropIfExists('ai_page_import_rows');
        Schema::dropIfExists('ai_page_imports');
        Schema::dropIfExists('ai_work_preferences');
    }
};
