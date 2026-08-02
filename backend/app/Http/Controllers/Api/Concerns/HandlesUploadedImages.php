<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            Storage::disk('public')->delete($path);
        }
    }
}
