<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('blocked_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 200);
            $table->string('normalized_term', 200);
            $table->string('locale', 8)->default('all')->index();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['normalized_term', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_terms');
        Schema::dropIfExists('system_settings');
    }
};
