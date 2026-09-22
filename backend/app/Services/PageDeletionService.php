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
        $mediaPaths = DB::transaction(fn (): array => $this->deleteInCurrentTransaction($page));

        $this->deleteMedia($mediaPaths);
    }

    /** @return list<string> */
    public function deleteInCurrentTransaction(Page $page): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Page deletion must run inside a database transaction.');
        }

        $page->load(['products' => fn ($q) => $q->withoutGlobalScope('community_visibility'), 'services' => fn ($q) => $q->withoutGlobalScope('community_visibility'), 'ads' => fn ($q) => $q->withoutGlobalScope('community_visibility'), 'events' => fn ($q) => $q->withoutGlobalScope('community_visibility')]);
        $mediaPaths = $this->mediaPaths($page);

        $page->ads()->withoutGlobalScope('community_visibility')->delete();
        $page->delete();

        return $mediaPaths;
    }

    /** @param list<string> $mediaPaths */
    public function deleteMedia(array $mediaPaths): void
    {
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
