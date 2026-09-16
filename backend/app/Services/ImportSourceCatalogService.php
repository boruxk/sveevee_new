<?php

namespace App\Services;

use App\Models\BusinessImportCategory;
use App\Models\BusinessImportCity;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Support\CatalogTopics;
use Normalizer;

/** Retain unknown source labels for review without changing the public catalog. */
class ImportSourceCatalogService
{
    private ?array $cityNames = null;

    private array $cityAliases = [];

    private array $resolvedCategories = [];

    /** Called inside the successful source import transaction, after ownership checks. */
    public function record(Page $page, array $source, array $input = []): void
    {
        $this->aggregate($source, $input, $page);
        $setup = $this->pageSetup($page, $source);
        if ($setup !== $page->setup) {
            $page->forceFill(['setup' => $setup])->save();
        }
    }

    /** Private label aggregation also accepts unresolved reviews, without changing any page. */
    public function aggregate(array $source, array $input = [], ?Page $example = null, bool $dryRun = false): array
    {
        $metadata = $source['metadata'];
        $rawCity = $this->text($metadata['source_city'] ?? $metadata['address']['locality'] ?? $metadata['original_record']['locality'] ?? $metadata['original_record']['שם עיר'] ?? null, 120);
        // The worker's canonical city is stronger evidence of a known alias than its original spelling.
        $city = $this->text($input['address']['city'] ?? null, 120) ?? $rawCity;
        $unknownCity = $city !== null && $this->knownCity($city) === null;
        $categories = $this->unknownCategories($metadata, $source['provider']);
        $counts = ['city_observations' => (int) $unknownCity, 'category_observations' => count($categories)];
        if ($dryRun) {
            return $counts;
        }
        $now = now();
        if ($unknownCity) {
            $rawCity ??= $city;
            BusinessImportCity::query()->upsert([[
                'provider' => $source['provider'], 'value_hash' => $this->valueHash($rawCity), 'raw_value' => $rawCity,
                'first_source_id' => $source['id'], 'example_page_id' => $example?->id, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]], ['provider', 'value_hash'], ['last_seen_at']);
            if ($example !== null) {
                BusinessImportCity::where('provider', $source['provider'])->where('value_hash', $this->valueHash($rawCity))
                    ->whereNull('example_page_id')->update(['example_page_id' => $example->id]);
            }
        }

        foreach ($categories as $entry) {
            BusinessImportCategory::query()->upsert([[
                'provider' => $source['provider'], 'value_hash' => $this->valueHash($entry['key']), 'raw_value' => $entry['key'], 'label' => $entry['label'],
                'first_source_id' => $source['id'], 'example_page_id' => $example?->id, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]], ['provider', 'value_hash'], ['last_seen_at']);
            if ($example !== null) {
                BusinessImportCategory::where('provider', $source['provider'])->where('value_hash', $this->valueHash($entry['key']))
                    ->whereNull('example_page_id')->update(['example_page_id' => $example->id]);
            }
        }

        return $counts;
    }

    /** Build current public labels without writing, including legacy Overture taxonomy. */
    public function pageSetup(Page $page, array $source): array
    {
        $setup = is_array($page->setup) ? $page->setup : [];
        $metadata = $source['metadata'];
        // Rebuild the display from current associations so corrections and mapped/removed
        // categories take effect while another source's unknown categories remain available.
        $associations = BusinessImportSource::query()->where('page_id', $page->id)->lazyById(100);
        $categories = [];
        $includedSource = false;
        foreach ($associations as $association) {
            if ($association->provider === $source['provider'] && $association->source_id === $source['id']) {
                $associationMetadata = $metadata;
                $includedSource = true;
            } else {
                $associationMetadata = $association->metadata;
            }
            foreach ($this->unknownCategories(is_array($associationMetadata) ? $associationMetadata : [], $association->provider) as $entry) {
                $categories[$this->categoryIdentity($entry)] = $entry;
            }
        }
        if (! $includedSource) {
            foreach ($this->unknownCategories($metadata, $source['provider']) as $entry) {
                $categories[$this->categoryIdentity($entry)] = $entry;
            }
        }

        $categories = array_values($categories);

        return $categories === ($setup['imported_categories'] ?? []) ? $setup : [...$setup, 'imported_categories' => $categories];
    }

    /** Recover source facts only; never infer a city/category from unrelated page data. */
    public function metadataInput(array $source): array
    {
        $metadata = $source['metadata'];
        $original = is_array($metadata['original_record'] ?? null) ? $metadata['original_record'] : [];
        $address = is_array($metadata['address'] ?? null) ? $metadata['address'] : [];
        $category = null;
        foreach ($this->descriptors($metadata, $source['provider']) as $descriptor) {
            if (! is_array($descriptor)) {
                continue;
            }
            foreach (['key', 'catalog_key'] as $field) {
                $key = $this->text($descriptor[$field] ?? null, 120);
                if ($key !== null && ($mapped = $this->knownCategory($source['provider'], $key)) !== null) {
                    $category ??= $mapped;
                }
            }
        }

        $city = $this->text($metadata['source_city'] ?? $address['locality'] ?? $original['locality'] ?? null, 120);

        return ['category_key' => $category, 'address' => [
            'city' => $this->knownCity($city) ?? $city,
            'street' => $this->text($address['freeform'] ?? $original['address'] ?? null, 255),
            'number' => $this->text($address['number'] ?? $original['house_number'] ?? null, 40),
            'neighborhood' => $this->text($address['neighborhood'] ?? $original['neighborhood'] ?? null, 120),
        ]];
    }

    /** Apply reviewed catalog aliases without replacing the original source evidence. */
    public function normalizeInput(array $input, array $source): array
    {
        $city = $input['address']['city'] ?? null;
        if (is_string($city) && ($known = $this->knownCity($city)) !== null) {
            $input['address']['city'] = $known;
        }
        if (! filled($input['category_key'] ?? null)) {
            $category = $this->metadataInput($source)['category_key'];
            if ($category !== null) {
                $input['category_key'] = $category;
            }
        }

        return $input;
    }

    /** Only source-specific, reviewed aliases can promote a raw source category. */
    public function knownCategory(string $provider, ?string $key): ?string
    {
        $key = $this->text($key, 255);
        if ($key === null) {
            return null;
        }
        $cacheKey = $provider.'|'.$key;
        if (! array_key_exists($cacheKey, $this->resolvedCategories)) {
            $target = config('import_category_aliases', [])[$provider][$key] ?? $key;
            $this->resolvedCategories[$cacheKey] = is_string($target)
                ? CatalogTopics::canonicalKeyForScope($target, CatalogTopics::SCOPE_BUSINESS_PAGES) : null;
        }

        return $this->resolvedCategories[$cacheKey];
    }

    /** Resolve only current catalog names and explicitly documented import aliases. */
    public function knownCity(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        // Build once per service instance; a 9,000-row scan must not repeatedly traverse the catalog.
        if ($this->cityNames === null) {
            $this->cityNames = [];
            foreach (config('locations.cities', []) as $city) {
                $name = $city['name'];
                $this->cityNames[CatalogTopics::locationSlug($name)] = $name;
            }
            foreach (config('import_city_aliases', []) as $city => $aliases) {
                $target = $this->cityNames[CatalogTopics::locationSlug($city)] ?? null;
                if ($target !== null && is_array($aliases)) {
                    foreach ($aliases as $alias) {
                        if (is_string($alias)) {
                            $this->cityAliases[$this->cityAliasKey($alias)][$target] = true;
                        }
                    }
                }
            }
        }
        $canonical = $this->cityNames[CatalogTopics::locationSlug($value)] ?? null;
        if ($canonical !== null) {
            return $canonical;
        }
        $matches = $this->cityAliases[$this->cityAliasKey($value)] ?? [];

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    private function cityAliasKey(string $value): string
    {
        return mb_strtolower(preg_replace('/\s*[-\x{05be}\x{2010}-\x{2015}]\s*/u', '-', $this->text($value, 120) ?? '') ?? '', 'UTF-8');
    }

    private function unknownCategories(array $metadata, string $provider): array
    {
        $categories = [];
        foreach ($this->descriptors($metadata, $provider) as $descriptor) {
            if (! is_array($descriptor) || ($key = $this->text($descriptor['key'] ?? null, 255)) === null
                || $this->knownCategory($provider, $key) !== null) {
                continue;
            }
            $mappedKey = $this->text($descriptor['catalog_key'] ?? null, 120);
            if ($mappedKey !== null && $this->knownCategory($provider, $mappedKey) !== null) {
                continue;
            }
            $categories[$this->valueHash($key)] ??= [
                'provider' => $provider, 'key' => $key, 'label' => $this->text($descriptor['label'] ?? null, 500) ?? $key,
            ];
        }

        return array_values($categories);
    }

    private function descriptors(array $metadata, string $provider): array
    {
        if (array_key_exists('source_categories', $metadata)) {
            return is_array($metadata['source_categories']) ? $metadata['source_categories'] : [];
        }
        // Current workers annotate even older prepared snapshots before importing them.
        // These fallbacks retain actual legacy source fields without inventing activities.
        if ($provider === 'overture_places') {
            $taxonomy = is_array($metadata['taxonomy'] ?? null) ? $metadata['taxonomy'] : [];
            $keys = [];
            foreach ([$taxonomy['primary'] ?? null, ...(is_array($taxonomy['alternates'] ?? null) ? $taxonomy['alternates'] : [])] as $value) {
                if (($key = $this->text($value, 255)) !== null) {
                    $keys[$key] = true;
                }
            }
            if ($keys === [] && ($basic = $this->text($metadata['basic_category'] ?? null, 255)) !== null) {
                $keys[$basic] = true;
            }

            return array_map(static fn (string $key): array => [
                'key' => $key, 'label' => str_replace('_', ' ', $key), 'catalog_key' => null,
            ], array_keys($keys));
        }
        if ($provider === 'data_gov_ckan' && ($metadata['profile'] ?? null) === 'beer_sheva_business_licenses') {
            $record = is_array($metadata['original_record'] ?? null) ? $metadata['original_record'] : [];
            foreach (['תאור רישיון', 'תיאור רישיון', 'תאור רשיון', 'תיאור רשיון'] as $field) {
                if (($description = $this->text($record[$field] ?? null, PHP_INT_MAX)) !== null) {
                    return $this->description($description);
                }
            }
        }
        if ($provider === 'tel_aviv_business_licenses') {
            $record = is_array($metadata['license'] ?? null) ? $metadata['license'] : [];
            $codes = array_values(array_unique(preg_split('/[^0-9]+/', $this->text($record['mahuiot'] ?? null, PHP_INT_MAX) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: []));
            $description = $this->text($record['t_hesber_mahut_esek'] ?? null, PHP_INT_MAX);
            if ($codes !== []) {
                return array_map(fn (string $code): array => [
                    'key' => 'license_code:'.$code, 'label' => count($codes) === 1 && $description !== null ? $description : $code,
                    'catalog_key' => null,
                ], $codes);
            }

            return $description === null ? [] : $this->description($description);
        }

        return [];
    }

    private function description(string $description): array
    {
        return [['key' => 'license_description:'.hash('sha256', $description), 'label' => $description, 'catalog_key' => null]];
    }

    private function categoryIdentity(array $category): string
    {
        return $category['provider'].'|'.$this->valueHash($category['key']);
    }

    private function valueHash(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        return hash('sha256', mb_strtolower($this->text($value, PHP_INT_MAX) ?? '', 'UTF-8'));
    }

    private function text(mixed $value, int $maximum): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\p{Z}\s]+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $maximum, 'UTF-8');
    }
}
