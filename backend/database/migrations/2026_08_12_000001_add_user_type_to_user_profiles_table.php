<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_profiles', 'user_type')) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('user_type')->nullable()->index()->after('neighborhood');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_profiles', 'user_type')) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropIndex('user_profiles_user_type_index');
            $table->dropColumn('user_type');
        });
    }
};
