<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            // The display address combines several independently validated address fields.
            $table->text('address')->nullable()->change();
        });
    }

    public function down(): void
    {
        $length = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR_LENGTH' : 'LENGTH';
        if (DB::table('pages')->whereRaw($length.'(address) > 255')->exists()) {
            throw new RuntimeException('Cannot shrink the page address column while longer addresses are stored.');
        }
        Schema::table('pages', function (Blueprint $table): void {
            $table->string('address', 255)->nullable()->change();
        });
    }
};
