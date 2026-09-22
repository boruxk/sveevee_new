<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->string('category_key', 120)->nullable();
            $table->string('city', 120);
            $table->string('neighborhood', 120)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['city', 'neighborhood', 'created_at', 'id'], 'questions_location_cursor');
            $table->index(['category_key', 'created_at', 'id'], 'questions_category_cursor');
            $table->index(['created_at', 'id']);
        });
        Schema::create('public_comments', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('public_comments')->cascadeOnDelete();
            $table->foreignId('recommended_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->text('body');
            $table->boolean('helpful')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id', 'id'], 'comments_target_cursor');
        });
        Schema::create('social_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at');
            $table->unique(['target_type', 'target_id', 'user_id']);
        });
        Schema::create('community_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->cascadeOnDelete();
            $table->string('category_key', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('neighborhood', 120)->nullable();
            $table->string('scope_key', 64);
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'scope_key']);
            $table->index(['category_key', 'city', 'neighborhood'], 'subscriptions_area');
        });
        Schema::create('community_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 1000);
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'target_type', 'target_id']);
            $table->index(['status', 'id']);
        });
        Schema::create('community_notification_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 191);
            $table->timestamp('created_at');
            $table->unique(['user_id', 'event_key'], 'community_notification_once');
        });
        foreach (['ads', 'page_events'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->timestamp('community_hidden_at')->nullable();
                $table->index(['created_at', 'id'], $name.'_community_cursor');
            });
        }
    }

    public function down(): void
    {
        foreach (['ads', 'page_events'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_community_cursor');
                $table->dropColumn('community_hidden_at');
            });
        }
        foreach (['community_notification_receipts', 'community_reports', 'community_subscriptions', 'social_likes', 'public_comments', 'local_questions'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
