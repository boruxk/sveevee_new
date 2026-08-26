<?php

namespace App\Services;

use App\Models\BlockedTerm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class BlockedTermService
{
    private const CACHE_KEY = 'sveevee.blocked-terms.v1';

    public function contains(mixed $value): bool
    {
        $text = $this->normalize($value);

        if ($text === '') {
            return false;
        }

        foreach ($this->activeTerms() as $term) {
            if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public function normalize(mixed $value): string
    {
        $text = mb_strtolower((string) $value, 'UTF-8');
        $text = strtr($text, [
            '@' => 'a', '4' => 'a', '0' => 'o', '1' => 'i', '!' => 'i', '3' => 'e',
            '$' => 's', '5' => 's', '7' => 't', 'ё' => 'е',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i',
            'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function activeTerms(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            if (! Schema::hasTable('blocked_terms')) {
                return [];
            }

            return BlockedTerm::query()
                ->where('active', true)
                ->pluck('normalized_term')
                ->filter()
                ->unique()
                ->values()
                ->all();
        });
    }
}
