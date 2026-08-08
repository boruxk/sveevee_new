<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_events', function (Blueprint $table) {
            $table->string('event_end_time', 5)->nullable()->after('event_time');
        });
    }

    public function down(): void
    {
        Schema::table('page_events', function (Blueprint $table) {
            $table->dropColumn('event_end_time');
        });
    }
};
