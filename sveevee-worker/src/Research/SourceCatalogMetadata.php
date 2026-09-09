<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use Sveevee\Worker\Research\Overture\TaxonomyMapper;

/** Original source labels for catalog comparison; these never alter business identity or classification. */
final class SourceCatalogMetadata
{
    public static function text(mixed $value, int $maximum = 500): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\p{Z}\s]+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $maximum, 'UTF-8');
    }

    public static function description(string $description, ?string $catalogKey): array
    {
        $full = self::text($description, PHP_INT_MAX);
        if ($full === null) {
            return [];
        }

        return [['key' => 'license_description:'.hash('sha256', $full),
            'label' => self::text($full), 'catalog_key' => $catalogKey]];
    }

    public static function overture(array $metadata): array
    {
        $taxonomy = is_array($metadata['taxonomy'] ?? null) ? $metadata['taxonomy'] : [];
        $primary = self::text($taxonomy['primary'] ?? null, 255);
        $keys = [];
        foreach ([$primary, ...(is_array($taxonomy['alternates'] ?? null) ? $taxonomy['alternates'] : [])] as $value) {
            if (($key = self::text($value, 255)) !== null) {
                $keys[$key] = true;
            }
        }
        if ($keys === [] && ($basic = self::text($metadata['basic_category'] ?? null, 255)) !== null) {
            $keys[$basic] = true;
        }
        $categories = [];
        foreach (array_keys($keys) as $key) {
            $key = (string) $key;
            $categories[] = ['key' => $key, 'label' => str_replace('_', ' ', $key),
                'catalog_key' => TaxonomyMapper::map($key === $primary ? $taxonomy : ['primary' => $key], true)];
        }

        return ['source_city' => self::text($metadata['address']['locality'] ?? null, 120), 'source_categories' => $categories];
    }
}
