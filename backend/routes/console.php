<?php

use App\Models\Ad;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ads:prune-expired', function () {
    $deleted = Ad::query()->expired()->delete();

    $this->info("Deleted {$deleted} expired ads.");
})->purpose('Delete ads after their one-week lifetime has ended');

Schedule::command('ads:prune-expired')->hourly();
