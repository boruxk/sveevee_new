<?php

use App\Models\Ad;
use App\Services\SeoPrerenderService;
use App\Services\SystemSettingsService;
use App\Support\PublicImageVariants;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ads:prune-expired', function () {
    $retentionDays = app(SystemSettingsService::class)->integer('ads.purge_after_expiry_days', 30);
    $cutoff = now()->subDays($retentionDays);
    $deleted = 0;

    Ad::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', $cutoff)
        ->chunkById(100, function ($ads) use (&$deleted): void {
            $ads->each(function (Ad $ad) use (&$deleted): void {
                $ad->delete();
                $deleted++;
            });
        });

    $this->info("Deleted {$deleted} expired ads.");
})->purpose('Permanently delete expired ads after the configured retention period');

Artisan::command('seo:prerender-public-pages {--dist= : Path to the built frontend dist directory}', function () {
    $result = app(SeoPrerenderService::class)->render($this->option('dist'));

    $this->info("Prerendered {$result['files']} files in {$result['dist']}.");
    $this->line("Marketing pages: {$result['marketing_pages']}");
    $this->line("Legal/register pages: {$result['information_pages']}");
    $this->line("Catalog hubs: {$result['catalog_hubs']}");
    $this->line("Business pages: {$result['business_pages']}");
    $this->line("Product pages: {$result['product_pages']}");
})->purpose('Generate static HTML for marketing, legal, registration, catalog, business, and product pages');

Artisan::command('images:generate-variants {--force : Recreate existing variants}', function () {
    if (! PublicImageVariants::canGenerate()) {
        $this->error('GD WebP image processing is not available.');

        return 1;
    }

    $disk = Storage::disk('public');
    $paths = collect($disk->allFiles())
        ->filter(fn (string $path): bool => preg_match('#^(media/listings|ads|events|products|services|profiles|pages/logos|pages/banners)/#', $path) === 1)
        ->filter(fn (string $path): bool => preg_match('/\.webp$/i', $path) === 1)
        ->filter(fn (string $path): bool => preg_match('/-\d+\.webp$/i', $path) !== 1)
        ->values();

    $created = 0;

    foreach ($paths as $path) {
        $created += PublicImageVariants::generateForPath($path, overwrite: (bool) $this->option('force'));
    }

    $this->info("Generated {$created} responsive variants for {$paths->count()} public uploads.");

    return 0;
})->purpose('Generate responsive WebP variants for existing public uploads');

Schedule::command('ads:prune-expired')->hourly();
