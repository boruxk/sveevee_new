<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('page_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->after('page_id')->constrained()->cascadeOnDelete();
            $table->index(['user_id', 'event_date', 'event_time'], 'page_events_user_date_time_index');
        });
    }

    public function down(): void
    {
        DB::table('page_events')->whereNull('page_id')->delete();

        Schema::table('page_events', function (Blueprint $table): void {
            $table->dropIndex('page_events_user_date_time_index');
            $table->dropConstrainedForeignId('user_id');
            $table->unsignedBigInteger('page_id')->nullable(false)->change();
        });
    }
};
