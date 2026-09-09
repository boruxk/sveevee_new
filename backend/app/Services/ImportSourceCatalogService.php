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
    /** Called inside the successful source import transaction, after ownership checks. */
    public function record(Page $page, array $source): void
    {
        $metadata = $source['metadata'];
        $setup = is_array($page->setup) ? $page->setup : [];
        $city = $this->text($setup['address']['city'] ?? null, 120);
        $now = now();
        if ($city !== null && CatalogTopics::resolveCitySlug(CatalogTopics::locationSlug($city)) === null) {
            // A canonical page city also proves that a translated original source spelling is known.
            $rawCity = $this->text($metadata['source_city'] ?? $metadata['address']['locality'] ?? $metadata['original_record']['שם עיר'] ?? null, 120) ?? $city;
            BusinessImportCity::query()->upsert([[
                'provider' => $source['provider'], 'value_hash' => $this->valueHash($rawCity), 'raw_value' => $rawCity,
                'first_source_id' => $source['id'], 'example_page_id' => $page->id, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]], ['provider', 'value_hash'], ['last_seen_at']);
        }

        foreach ($this->unknownCategories($metadata, $source['provider']) as $entry) {
            BusinessImportCategory::query()->upsert([[
                'provider' => $source['provider'], 'value_hash' => $this->valueHash($entry['key']), 'raw_value' => $entry['key'], 'label' => $entry['label'],
                'first_source_id' => $source['id'], 'example_page_id' => $page->id, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]], ['provider', 'value_hash'], ['last_seen_at']);
        }
        // Rebuild the display from current associations so corrections and mapped/removed
        // categories take effect while another source's unknown categories remain available.
        $associations = BusinessImportSource::query()->where('page_id', $page->id)->orderBy('updated_at')->orderBy('id')->get();
        $categories = [];
        foreach ($associations as $association) {
            if ($association->provider === $source['provider'] && $association->source_id === $source['id']) {
                continue;
            }
            foreach ($this->unknownCategories($association->metadata, $association->provider) as $entry) {
                $categories[$this->categoryIdentity($entry)] = $entry;
            }
        }
        foreach ($this->unknownCategories($metadata, $source['provider']) as $entry) {
            $categories[$this->categoryIdentity($entry)] = $entry;
        }
        if (array_values($categories) !== ($setup['imported_categories'] ?? [])) {
            $page->forceFill(['setup' => [...$setup, 'imported_categories' => array_values($categories)]])->save();
        }
    }

    private function unknownCategories(array $metadata, string $provider): array
    {
        $categories = [];
        foreach ($this->descriptors($metadata, $provider) as $descriptor) {
            if (! is_array($descriptor) || ($key = $this->text($descriptor['key'] ?? null, 255)) === null
                || CatalogTopics::canonicalKeyForScope($key, CatalogTopics::SCOPE_BUSINESS_PAGES) !== null) {
                continue;
            }
            $mappedKey = $this->text($descriptor['catalog_key'] ?? null, 120);
            if ($mappedKey !== null && CatalogTopics::canonicalKeyForScope($mappedKey, CatalogTopics::SCOPE_BUSINESS_PAGES) !== null) {
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
