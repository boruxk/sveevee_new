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
            $table->dropIndex(['normalized_website']);
        });
        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->text('normalized_website')->nullable()->change();
            // A 40-character accepted phone can grow when its local prefix becomes 972.
            $table->string('normalized_phone', 64)->nullable()->change();
        });
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('CREATE INDEX page_identity_keys_normalized_website_index ON page_identity_keys (normalized_website(191))');
        } else {
            Schema::table('page_identity_keys', function (Blueprint $table): void {
                $table->index('normalized_website');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('page_identity_keys')->whereRaw('LENGTH(normalized_website) > 255 OR LENGTH(normalized_phone) > 32')->exists()) {
            throw new RuntimeException('Cannot shrink identity contact columns while longer values are stored.');
        }
        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->dropIndex(['normalized_website']);
        });
        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->string('normalized_website', 255)->nullable()->change();
            $table->string('normalized_phone', 32)->nullable()->change();
            $table->index('normalized_website');
        });
    }
};
