<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_profiles', 'languages')) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('languages');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_profiles', 'languages')) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->json('languages')->nullable()->after('neighborhood');
        });
    }
};
