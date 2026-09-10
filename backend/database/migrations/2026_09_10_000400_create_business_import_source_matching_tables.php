<?php

use App\Models\BusinessImportSource;
use App\Services\FoursquareImportMatchingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_import_source_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('source_id', 255);
            $table->foreignId('business_import_source_id')->constrained('business_import_sources')->cascadeOnDelete();
            $table->unique(['provider', 'source_id', 'business_import_source_id'], 'business_source_alias_unique');
        });
        Schema::create('business_import_match_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('source_id', 255);
            $table->string('reason', 80);
            $table->string('status', 32)->default('pending')->index();
            $table->json('payload');
            $table->json('candidates');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['provider', 'source_id'], 'business_match_review_source_unique');
        });
        BusinessImportSource::where('provider', 'overture_places')->select(['id', 'provider', 'metadata'])
            ->chunkById(200, function ($sources): void {
                $aliases = [];
                foreach ($sources as $source) {
                    foreach (FoursquareImportMatchingService::aliasIds($source->metadata) as $id) {
                        $aliases[] = ['provider' => 'foursquare_places', 'source_id' => $id, 'business_import_source_id' => $source->id];
                    }
                }
                foreach (array_chunk($aliases, 200) as $chunk) {
                    DB::table('business_import_source_aliases')->insert($chunk);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_import_match_reviews');
        Schema::dropIfExists('business_import_source_aliases');
    }
};
