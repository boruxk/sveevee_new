<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the purchased tier on historical receipts even after a later plan change.
        Schema::table('business_pro_payments', function (Blueprint $table): void {
            $table->string('plan_key', 50)->default('business_pro');
        });

        DB::table('business_pro_features')->insertOrIgnore([
            'key' => 'featured_ads',
            'labels' => json_encode(['en' => 'Featured ads', 'he' => 'מודעות מובלטות', 'ru' => 'Выделенные объявления', 'fr' => 'Annonces à la une'], JSON_UNESCAPED_UNICODE),
            'descriptions' => json_encode([
                'en' => 'Highlight your ads and give them priority within relevant results.',
                'he' => 'הבליטו את המודעות שלכם והעניקו להן עדיפות בתוצאות הרלוונטיות.',
                'ru' => 'Выделяйте свои объявления и поднимайте их выше среди подходящих результатов.',
                'fr' => 'Mettez vos annonces en valeur et donnez-leur la priorité parmi les résultats pertinents.',
            ], JSON_UNESCAPED_UNICODE),
            'lifecycle' => 'published', 'enabled' => true, 'sort_order' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('business_pro_features')->where('key', 'featured_ads')->delete();
        Schema::table('business_pro_payments', fn (Blueprint $table) => $table->dropColumn('plan_key'));
    }
};
