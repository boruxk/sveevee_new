<?php

use App\Models\Page;
use App\Services\PageIdentityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->char('import_location_hash', 64)->nullable()->index();
            $table->char('import_name_city_hash', 64)->nullable()->index();
        });

        // Populate lookup keys for existing pages without changing the pages or import tracking.
        $identities = app(PageIdentityService::class);
        Page::query()->chunkById(100, function ($pages) use ($identities): void {
            foreach ($pages as $page) {
                $identities->sync($page);
            }
        });
    }

    public function down(): void
    {
        Schema::table('page_identity_keys', function (Blueprint $table): void {
            $table->dropIndex(['import_location_hash']);
            $table->dropColumn('import_location_hash');
            $table->dropIndex(['import_name_city_hash']);
            $table->dropColumn('import_name_city_hash');
        });
    }
};
