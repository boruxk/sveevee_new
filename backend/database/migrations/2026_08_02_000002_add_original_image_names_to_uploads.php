<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('photo_original_name')->nullable()->after('photo_path');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->string('logo_original_name')->nullable()->after('logo_path');
            $table->string('banner_original_name')->nullable()->after('banner_path');
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->string('image_original_name')->nullable()->after('image_path');
        });

        Schema::table('page_products', function (Blueprint $table) {
            $table->string('image_original_name')->nullable()->after('image_path');
        });

        Schema::table('page_events', function (Blueprint $table) {
            $table->string('image_original_name')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('page_events', function (Blueprint $table) {
            $table->dropColumn('image_original_name');
        });

        Schema::table('page_products', function (Blueprint $table) {
            $table->dropColumn('image_original_name');
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->dropColumn('image_original_name');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['logo_original_name', 'banner_original_name']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('photo_original_name');
        });
    }
};
