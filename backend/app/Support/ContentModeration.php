<?php

namespace App\Support;

use App\Services\BlockedTermService;

class ContentModeration
{
    private const PATTERNS = [
        '/(?<![\p{L}\p{N}])f+\s*u+\s*c+\s*k+(?:\s*(?:e+\s*d+|e+\s*r+|i+\s*n+\s*g+|s+))?(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])m+\s*o+\s*t+\s*h+\s*e+\s*r+\s*f+\s*u+\s*c+\s*k+\s*e+\s*r+(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])s+\s*h+\s*i+\s*t+(?:\s*t+\s*y+)?(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])b+\s*i+\s*t+\s*c+\s*h+(?:\s*e+\s*s+)?(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])a+\s*s+\s*s+\s*h+\s*o+\s*l+\s*e+(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])(?:b+\s*a+\s*s+\s*t+\s*a+\s*r+\s*d+|d+\s*i+\s*c+\s*k+|p+\s*u+\s*s+\s*s+\s*y+|c+\s*u+\s*n+\s*t+|w+\s*h+\s*o+\s*r+\s*e+|s+\s*l+\s*u+\s*t+)(?![\p{L}\p{N}])/u',
        '/(?<![\p{L}\p{N}])(?:i+\s*d+\s*i+\s*o+\s*t+|m+\s*o+\s*r+\s*o+\s*n+|r+\s*e+\s*t+\s*a+\s*r+\s*d+(?:\s*e+\s*d+)?)(?![\p{L}\p{N}])/u',

        '/(?<![\p{L}\p{N}])(?:merde|putain|connards?|connasses?|connes?|salope?s?|putes?|enfoiree?s?|batarde?s?|couilles?|bites?)(?![\p{L}\p{N}])/u',

        '/(?<![\p{L}\p{N}])(?:бля(?:дь|ть)?|сука|сучк\p{L}*|хуй|хуе\p{L}*|пизд\p{L}*|еба\p{L}*|мудак\p{L}*|мразь|шлюх\p{L}*|дерьм\p{L}*|говн\p{L}*|дебил\p{L}*|идиот\p{L}*)(?![\p{L}\p{N}])/u',

        '/(?<![\p{L}\p{N}])(?:חרא|זונה|זונות|שרמוטה|שרמוטות|מניאק|מטומטם|מטומטמת|מפגר|מפגרת|מזדיין|מזדיינת|בן\s*זונה|בת\s*זונה|בנזונה|כוסאמק|כוסעמק)(?![\p{L}\p{N}])/u',
    ];

    public static function containsBlockedLanguage(mixed $value): bool
    {
        $text = self::normalize($value);

        if ($text === '') {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return app(BlockedTermService::class)->contains($value);
    }

    private static function normalize(mixed $value): string
    {
        $text = mb_strtolower((string) $value, 'UTF-8');
        $text = strtr($text, [
            '@' => 'a',
            '4' => 'a',
            '0' => 'o',
            '1' => 'i',
            '!' => 'i',
            '3' => 'e',
            '$' => 's',
            '5' => 's',
            '7' => 't',
            'ё' => 'е',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
        ]);

        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
