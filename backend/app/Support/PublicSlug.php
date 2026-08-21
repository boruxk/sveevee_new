<?php

namespace App\Support;

use Illuminate\Support\Str;

class PublicSlug
{
    public static function make(array $parts, string $fallback, int|string|null $id): string
    {
        $slugParts = collect($parts)
            ->map(fn ($part): string => self::part((string) $part))
            ->filter()
            ->values()
            ->all();

        if ($slugParts === []) {
            $slugParts[] = $fallback;
        }

        if ($id) {
            $slugParts[] = (string) $id;
        }

        return implode('-', $slugParts);
    }

    public static function idFromSlug(string $value): ?int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/-(\d+)$/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private static function part(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?: '';

        return trim($value, '-');
    }
}
