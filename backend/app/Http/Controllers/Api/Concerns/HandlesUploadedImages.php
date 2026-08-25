<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\PublicImageVariants;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait HandlesUploadedImages
{
    protected function originalUploadName(Request $request, string $field, ?UploadedFile $file = null): ?string
    {
        $name = $request->input($field.'_original_name') ?: $file?->getClientOriginalName();
        $name = basename(str_replace('\\', '/', trim(str_replace("\0", '', (string) $name))));

        return $name === '' ? null : Str::limit($name, 255, '');
    }

    protected function deletePublicUpload(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete([
                $path,
                ...PublicImageVariants::variantPaths($path),
            ]);
        }
    }

    protected function storePublicWebp(UploadedFile $file, string $directory, string $field = 'image', int $quality = 84): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages([
                $field => 'Image conversion is not available on this server.',
            ]);
        }

        $source = @file_get_contents($file->getRealPath());
        $image = $source ? @imagecreatefromstring($source) : false;

        if (! $image) {
            throw ValidationException::withMessages([
                $field => 'The uploaded image could not be processed.',
            ]);
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';

        try {
            Storage::disk('public')->put($path, $this->webpContents($image, $field, $quality));
            PublicImageVariants::generateForImage($image, $path, $quality, true);
        } finally {
            imagedestroy($image);
        }

        return $path;
    }

    private function webpContents(\GdImage $image, string $field, int $quality): string
    {
        ob_start();
        $converted = imagewebp($image, null, $quality);
        $contents = ob_get_clean();

        if (! $converted || ! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                $field => 'The uploaded image could not be converted.',
            ]);
        }

        return $contents;
    }
}
