<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('business_pro_tester')->default(false);
        });

        Schema::create('business_pro_features', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('labels');
            $table->json('descriptions')->nullable();
            $table->string('lifecycle', 20)->default('draft');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_pro_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_key', 50)->default('business_pro');
            $table->unsignedInteger('included_pages')->default(1);
            $table->string('status', 24)->default('inactive')->index();
            $table->string('environment', 16);
            $table->unsignedInteger('terminal_number');
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3)->default('ILS');
            $table->string('interval', 16)->default('monthly');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_charge_at')->nullable()->index();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('provider_token')->nullable();
            $table->string('provider_customer_id')->nullable();
            $table->unsignedTinyInteger('token_expires_month')->nullable();
            $table->unsignedSmallInteger('token_expires_year')->nullable();
            $table->unsignedBigInteger('last_payment_id')->nullable();
            $table->timestamps();
        });

        Schema::create('business_pro_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('subscription_id')->constrained('business_pro_subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('initial');
            $table->string('status', 20)->default('pending')->index();
            $table->string('environment', 16);
            $table->unsignedInteger('terminal_number');
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3)->default('ILS');
            $table->string('provider_low_profile_id')->nullable()->unique();
            $table->string('provider_transaction_id')->nullable()->index();
            $table->text('checkout_url')->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'terminal_number', 'provider_transaction_id'], 'bp_payment_provider_transaction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_pro_payments');
        Schema::dropIfExists('business_pro_subscriptions');
        Schema::dropIfExists('business_pro_features');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('business_pro_tester'));
    }
};
