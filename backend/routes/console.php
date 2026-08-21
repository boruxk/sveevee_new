<?php

use App\Models\Ad;
use App\Services\SeoPrerenderService;
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

Artisan::command('seo:prerender-public-pages {--dist= : Path to the built frontend dist directory}', function () {
    $result = app(SeoPrerenderService::class)->render($this->option('dist'));

    $this->info("Prerendered {$result['files']} files in {$result['dist']}.");
    $this->line("Business pages: {$result['business_pages']}");
    $this->line("Product pages: {$result['product_pages']}");
})->purpose('Generate static HTML for public SEO business and product pages');

Schedule::command('ads:prune-expired')->hourly();
