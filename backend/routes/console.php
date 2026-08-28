<?php

use App\Models\Ad;
use App\Models\GuestSupportConversation;
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

Artisan::command('support:prune-guest-chats', function () {
    $retentionDays = app(SystemSettingsService::class)->integer('chat.guest_retention_days', 90);
    $cutoff = now()->subDays($retentionDays);
    $deleted = 0;

    GuestSupportConversation::query()
        ->where(function ($query) use ($cutoff): void {
            $query->where(function ($claimed) use ($cutoff): void {
                $claimed->whereNotNull('claimed_at')->where('claimed_at', '<=', $cutoff);
            })->orWhere(function ($active) use ($cutoff): void {
                $active->whereNull('claimed_at')
                    ->where(function ($activity) use ($cutoff): void {
                        $activity->where('last_message_at', '<=', $cutoff)
                            ->orWhere(function ($empty) use ($cutoff): void {
                                $empty->whereNull('last_message_at')->where('created_at', '<=', $cutoff);
                            });
                    });
            });
        })
        ->chunkById(100, function ($conversations) use (&$deleted): void {
            $conversations->each(function (GuestSupportConversation $conversation) use (&$deleted): void {
                $conversation->delete();
                $deleted++;
            });
        });

    $this->info("Deleted {$deleted} inactive guest support conversations.");
})->purpose('Delete inactive guest support conversations after the configured retention period');

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
Schedule::command('support:prune-guest-chats')->hourly();
