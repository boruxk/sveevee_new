<?php

namespace App\Services;

use App\Models\Page;
use App\Support\PublicImageVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PageDeletionService
{
    public function delete(Page $page): void
    {
        $page->loadMissing(['ads', 'products', 'services', 'events']);
        $mediaPaths = $this->mediaPaths($page);

        DB::transaction(function () use ($page): void {
            $page->ads()->delete();
            $page->delete();
        });

        if ($mediaPaths !== []) {
            Storage::disk('public')->delete($mediaPaths);
        }
    }

    /** @return list<string> */
    private function mediaPaths(Page $page): array
    {
        return collect([$page->logo_path, $page->banner_path])
            ->merge($page->ads->pluck('image_path'))
            ->merge($page->products->pluck('image_path'))
            ->merge($page->services->pluck('image_path'))
            ->merge($page->events->pluck('image_path'))
            ->filter(fn ($path): bool => filled($path))
            ->flatMap(fn (string $path): array => [
                $path,
                ...PublicImageVariants::variantPaths($path),
            ])
            ->unique()
            ->values()
            ->all();
    }
}
