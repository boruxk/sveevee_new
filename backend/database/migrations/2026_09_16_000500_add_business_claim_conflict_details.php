<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_claim_requests', function (Blueprint $table): void {
            $table->string('kind', 24)->default('ownership');
            $table->json('proposed_data')->nullable();
            $table->json('matched_on')->nullable();
            $table->foreignId('owner_at_request_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('owner_at_request_claimed_at')->nullable();
            $table->boolean('owner_at_request_is_unclaimed')->default(false);
            $table->uuid('conflict_group_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('page_claim_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_at_request_user_id');
            $table->dropIndex(['conflict_group_id']);
            $table->dropColumn(['kind', 'proposed_data', 'matched_on', 'owner_at_request_claimed_at', 'owner_at_request_is_unclaimed', 'conflict_group_id']);
        });
    }
};
