<?php

namespace App\Services;

use App\Models\User;
use App\Support\PublicImageVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class UserDeletionService
{
    public function delete(User $user): void
    {
        $user->load([
            'profile',
            'ads',
            'pages.products',
            'pages.services',
            'pages.events',
        ]);

        $mediaPaths = $this->mediaPaths($user);

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        if ($mediaPaths !== []) {
            Storage::disk('public')->delete($mediaPaths);
        }
    }

    /** @return list<string> */
    private function mediaPaths(User $user): array
    {
        return collect([$user->profile?->photo_path])
            ->merge($user->ads->pluck('image_path'))
            ->merge($user->pages->flatMap(fn ($page): array => [
                $page->logo_path,
                $page->banner_path,
                ...$page->products->pluck('image_path')->all(),
                ...$page->services->pluck('image_path')->all(),
                ...$page->events->pluck('image_path')->all(),
            ]))
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
