<?php

namespace App\Console\Commands;

use App\Models\BusinessProFeature;
use Illuminate\Console\Command;

class SyncBusinessProFeatures extends Command
{
    protected $signature = 'business-pro:sync-features';

    protected $description = 'Register implemented Business Pro feature definitions as disabled drafts without publishing them';

    public function handle(): int
    {
        $created = 0;
        foreach ((array) config('business_pro.features', []) as $key => $definition) {
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $key) !== 1 || ! is_array($definition)) {
                $this->error('Invalid Business Pro feature definition.');

                return self::FAILURE;
            }
            $feature = BusinessProFeature::query()->firstOrCreate(['key' => $key], [
                'labels' => (array) ($definition['labels'] ?? ['en' => $key]),
                'descriptions' => (array) ($definition['descriptions'] ?? []),
                'lifecycle' => 'draft', 'enabled' => false,
                'sort_order' => (int) ($definition['sort_order'] ?? 0),
            ]);
            $created += $feature->wasRecentlyCreated ? 1 : 0;
        }
        $this->info("Registered {$created} Business Pro feature definitions. Existing controls were preserved.");

        return self::SUCCESS;
    }
}
