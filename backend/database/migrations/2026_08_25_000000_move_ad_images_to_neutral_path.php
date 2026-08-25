<?php

use App\Support\PublicImageVariants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $this->moveAdImages('ads', 'media/listings');
    }

    public function down(): void
    {
        $this->moveAdImages('media/listings', 'ads');
    }

    private function moveAdImages(string $sourceDirectory, string $targetDirectory): void
    {
        if (! Schema::hasTable('ads')) {
            return;
        }

        DB::table('ads')
            ->where('image_path', 'like', $sourceDirectory.'/%')
            ->select(['id', 'image_path'])
            ->orderBy('id')
            ->chunkById(100, function ($ads) use ($sourceDirectory, $targetDirectory): void {
                foreach ($ads as $ad) {
                    $targetPath = $targetDirectory.substr($ad->image_path, strlen($sourceDirectory));
                    $this->moveImageFiles($ad->image_path, $targetPath);

                    DB::table('ads')
                        ->where('id', $ad->id)
                        ->update(['image_path' => $targetPath]);
                }
            });
    }

    private function moveImageFiles(string $sourcePath, string $targetPath): void
    {
        $disk = Storage::disk('public');
        $sourcePaths = [$sourcePath, ...PublicImageVariants::variantPaths($sourcePath)];
        $targetPaths = [$targetPath, ...PublicImageVariants::variantPaths($targetPath)];

        foreach ($sourcePaths as $index => $path) {
            if (! $disk->exists($path) || $disk->exists($targetPaths[$index])) {
                continue;
            }

            if (! $disk->move($path, $targetPaths[$index])) {
                throw new RuntimeException("Could not move public image {$path} to {$targetPaths[$index]}.");
            }
        }
    }
};
