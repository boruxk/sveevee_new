<?php

use App\Models\BusinessImportSource;
use App\Services\FoursquareImportMatchingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        BusinessImportSource::where('provider', 'overture_places')->select(['id', 'metadata'])
            ->chunkById(200, function ($sources): void {
                foreach ($sources as $source) {
                    foreach (FoursquareImportMatchingService::osmAliasIds($source->metadata) as $id) {
                        DB::table('business_import_source_aliases')->insertOrIgnore([
                            'provider' => 'osm_places', 'source_id' => $id, 'business_import_source_id' => $source->id,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('business_import_source_aliases')->where('provider', 'osm_places')->delete();
    }
};
