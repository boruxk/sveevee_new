<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('status')->index();
        });

        DB::table('ads')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $ad): void {
                DB::table('ads')
                    ->where('id', $ad->id)
                    ->update([
                        'expires_at' => Carbon::parse($ad->created_at ?? now())->addWeek(),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
