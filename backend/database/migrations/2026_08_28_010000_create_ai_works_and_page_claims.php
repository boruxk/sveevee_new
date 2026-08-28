<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AI_LOGIN = 'spfksfmbvpt';

    private const AI_EMAIL = 'ai-works@sveevee.local';

    private const AI_PASSWORD_HASH = '$2y$12$Lc8NZOb8TB1k79B2hEIR2eL93Cuae.ihQR7DKELniLyq.GGoTxoB2';

    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->index('user_id', 'pages_user_id_index');
            $table->dropUnique('pages_user_id_type_unique');
            $table->boolean('is_unclaimed')->default(false)->after('type')->index();
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('source_url', 2048)->nullable()->after('setup');
            $table->date('source_checked_at')->nullable()->after('source_url');
            $table->timestamp('claimed_at')->nullable()->after('source_checked_at');
        });

        Schema::create('ai_work_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('text');
            $table->timestamps();
        });

        Schema::create('page_claim_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->text('message');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        $now = now();
        $account = DB::table('users')
            ->where('login', self::AI_LOGIN)
            ->orWhere('email', self::AI_EMAIL)
            ->first();
        $values = [
            'login' => self::AI_LOGIN,
            'name' => 'Sveevee AI Works',
            'given_name' => 'Sveevee',
            'family_name' => 'AI Works',
            'email' => self::AI_EMAIL,
            'email_verified_at' => $now,
            'password' => self::AI_PASSWORD_HASH,
            'locale' => 'he',
            'consented' => true,
            'role' => 'ai_worker',
            'banned_at' => null,
            'banned_reason' => null,
            'updated_at' => $now,
        ];

        if ($account) {
            DB::table('users')->where('id', $account->id)->update($values);
        } else {
            DB::table('users')->insert([...$values, 'created_at' => $now]);
        }

        $accountId = DB::table('users')->where('login', self::AI_LOGIN)->value('id');

        if ($accountId && ! DB::table('user_profiles')->where('user_id', $accountId)->exists()) {
            DB::table('user_profiles')->insert([
                'user_id' => $accountId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_claim_requests');
        Schema::dropIfExists('ai_work_tasks');

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn([
                'is_unclaimed',
                'created_by_user_id',
                'source_url',
                'source_checked_at',
                'claimed_at',
            ]);
        });

        $hasDuplicateOwners = DB::table('pages')
            ->select('user_id', 'type')
            ->groupBy('user_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDuplicateOwners) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->unique(['user_id', 'type']);
                $table->dropIndex('pages_user_id_index');
            });
        }
    }
};
