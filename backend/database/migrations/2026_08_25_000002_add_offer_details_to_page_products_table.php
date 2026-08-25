<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_products', function (Blueprint $table) {
            $table->string('brand', 120)->nullable()->after('name');
            $table->string('model', 120)->nullable()->after('brand');
            $table->boolean('offer_enabled')->default(false)->after('price');
            $table->decimal('offer_price', 10, 2)->nullable()->after('offer_enabled');
            $table->dateTime('offer_starts_at')->nullable()->after('offer_price');
            $table->dateTime('offer_ends_at')->nullable()->after('offer_starts_at');
            $table->decimal('previous_price', 10, 2)->nullable()->after('offer_ends_at');
            $table->unsignedBigInteger('views_count')->default(0)->after('previous_price');
            $table->unsignedBigInteger('contacts_count')->default(0)->after('views_count');
        });
    }

    public function down(): void
    {
        Schema::table('page_products', function (Blueprint $table) {
            $table->dropColumn([
                'brand',
                'model',
                'offer_enabled',
                'offer_price',
                'offer_starts_at',
                'offer_ends_at',
                'previous_price',
                'views_count',
                'contacts_count',
            ]);
        });
    }
};
