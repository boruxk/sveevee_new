<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversations', 'is_support')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->boolean('is_support')->default(false)->after('started_by_user_id')->index();
            });
        }

        $supportAdminId = DB::table('users')
            ->where('email', config('sveevee.support_admin_email', 'support@sveevee.local'))
            ->value('id');

        if ($supportAdminId) {
            DB::table('conversations')
                ->where('user_one_id', $supportAdminId)
                ->orWhere('user_two_id', $supportAdminId)
                ->update(['is_support' => true]);
        }

        if (DB::getDriverName() !== 'sqlite') {
            $this->addIndexIfMissing('conversations_user_one_id_index', 'user_one_id');
            $this->addIndexIfMissing('conversations_user_two_id_index', 'user_two_id');

            Schema::table('conversations', function (Blueprint $table): void {
                if ($this->indexExists('conversations_user_one_id_user_two_id_unique')) {
                    $table->dropUnique('conversations_user_one_id_user_two_id_unique');
                }

                if (! $this->indexExists('conversations_pair_support_unique')) {
                    $table->unique(['user_one_id', 'user_two_id', 'is_support'], 'conversations_pair_support_unique');
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('conversations', 'is_support')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('conversations', function (Blueprint $table): void {
                if ($this->indexExists('conversations_pair_support_unique')) {
                    $table->dropUnique('conversations_pair_support_unique');
                }

                if (! $this->indexExists('conversations_user_one_id_user_two_id_unique')) {
                    $table->unique(['user_one_id', 'user_two_id'], 'conversations_user_one_id_user_two_id_unique');
                }
            });
        }

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('is_support');
        });
    }

    private function addIndexIfMissing(string $indexName, string $column): void
    {
        if ($this->indexExists($indexName)) {
            return;
        }

        DB::statement("ALTER TABLE `conversations` ADD INDEX `{$indexName}` (`{$column}`)");
    }

    private function indexExists(string $indexName): bool
    {
        return DB::select('SHOW INDEX FROM `conversations` WHERE Key_name = ?', [$indexName]) !== [];
    }
};
