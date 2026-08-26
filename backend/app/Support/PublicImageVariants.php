<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class PublicImageVariants
{
    public static function canGenerate(): bool
    {
        return function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagewebp');
    }

    public static function widthsForPath(?string $path): array
    {
        $path = (string) $path;

        if (str_contains($path, 'pages/banners')) {
            return [480, 768, 960, 1440];
        }

        if (str_contains($path, 'profiles') || str_contains($path, 'pages/logos')) {
            return [160, 320, 640];
        }

        return [384, 576, 768, 1200];
    }

    public static function variantPath(string $path, int $width): string
    {
        if (str_ends_with($path, '.webp')) {
            return substr($path, 0, -5).'-'.$width.'.webp';
        }

        return $path.'-'.$width.'.webp';
    }

    public static function variantPaths(?string $path): array
    {
        if (! filled($path)) {
            return [];
        }

        $paths = collect(self::widthsForPath($path))
            ->flatMap(function (int $width) use ($path): array {
                $webp = self::variantPath($path, $width);

                return [$webp, preg_replace('/\.webp$/i', '.avif', $webp) ?: $webp.'.avif'];
            });

        if (str_ends_with(strtolower($path), '.webp')) {
            $paths->push(substr($path, 0, -5).'.avif');
        }

        return $paths
            ->unique()
            ->values()
            ->all();
    }

    public static function webpSrcset(?string $path): string
    {
        if (! filled($path)) {
            return '';
        }

        $disk = Storage::disk('public');
        $items = collect(self::widthsForPath($path))
            ->map(function (int $width) use ($disk, $path): ?string {
                $variant = self::variantPath($path, $width);

                return $disk->exists($variant)
                    ? url($disk->url($variant)).' '.$width.'w'
                    : null;
            })
            ->filter()
            ->values();

        [$width] = self::dimensions($path);

        if ($width && $disk->exists($path)) {
            $original = url($disk->url($path)).' '.$width.'w';

            if (! $items->contains($original)) {
                $items->push($original);
            }
        }

        return $items->implode(', ');
    }

    public static function dimensions(?string $path): array
    {
        if (! filled($path)) {
            return [null, null];
        }

        $disk = Storage::disk('public');

        if (! method_exists($disk, 'path') || ! $disk->exists($path)) {
            return [null, null];
        }

        $size = @getimagesize($disk->path($path));

        return is_array($size) ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    public static function generateForPath(?string $path, int $quality = 84, bool $overwrite = false): int
    {
        if (! filled($path) || ! self::canGenerate()) {
            return 0;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return 0;
        }

        $source = @file_get_contents($disk->path($path));
        $image = $source ? @imagecreatefromstring($source) : false;

        if (! $image) {
            return 0;
        }

        try {
            return self::generateForImage($image, $path, $quality, $overwrite);
        } finally {
            imagedestroy($image);
        }
    }

    public static function generateForImage(\GdImage $source, string $path, int $quality = 84, bool $overwrite = false): int
    {
        if (! self::canGenerate()) {
            return 0;
        }

        self::prepareImage($source);

        $disk = Storage::disk('public');
        $created = 0;

        foreach (self::widthsForPath($path) as $width) {
            $variantPath = self::variantPath($path, $width);

            if (! $overwrite && $disk->exists($variantPath)) {
                continue;
            }

            $variant = self::resizedImage($source, $width);

            if (! $variant) {
                continue;
            }

            try {
                $contents = self::webpContents($variant, $quality);

                if ($contents === null) {
                    continue;
                }

                $disk->put($variantPath, $contents);
                $created++;
            } finally {
                imagedestroy($variant);
            }
        }

        return $created;
    }

    private static function prepareImage(\GdImage $image): void
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    private static function resizedImage(\GdImage $source, int $targetWidth): ?\GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($targetWidth >= $sourceWidth || $sourceWidth <= 0 || $sourceHeight <= 0) {
            return null;
        }

        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return $target;
    }

    private static function webpContents(\GdImage $image, int $quality): ?string
    {
        ob_start();
        $converted = imagewebp($image, null, $quality);
        $contents = ob_get_clean();

        return $converted && is_string($contents) && $contents !== '' ? $contents : null;
    }
}
