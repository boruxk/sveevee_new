<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_page_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('status', 24)->default('new')->index();
            $table->string('business_name');
            $table->string('city', 120);
            $table->string('category_key');
            $table->string('full_name');
            $table->string('email')->index();
            $table->string('phone', 40);
            $table->string('locale', 5)->default('he');
            $table->boolean('created_page')->default(true);
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('fbclid', 500)->nullable();
            $table->text('landing_url')->nullable();
            $table->char('ip_hash', 64)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->timestamp('consented_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_page_leads');
    }
};
