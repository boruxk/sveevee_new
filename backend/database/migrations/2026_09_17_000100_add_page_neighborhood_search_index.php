<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Match each driver's existing JSON selector, including its NULL behavior.
        // Index only a bounded prefix: imported legacy values must never make a
        // schema change or a later insert fail because their neighborhood is long.
        // Search also retains the original full JSON equality after this prefilter.
        $connection = DB::connection();
        $selector = $connection->getQueryGrammar()->wrap('setup->address->neighborhood');

        Schema::table('pages', function (Blueprint $table) use ($connection, $selector): void {
            $column = $table->string('search_neighborhood_prefix', 191)->nullable()
                ->virtualAs("substr({$selector}, 1, 191)");
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('utf8mb4')->collation('utf8mb4_bin');
            }
            $table->index(['search_neighborhood_prefix', 'created_at', 'id'], 'pages_neighborhood_created_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex('pages_neighborhood_created_id_index');
            $table->dropColumn('search_neighborhood_prefix');
        });
    }
};
