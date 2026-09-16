<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\OpenStreetMap;

use RuntimeException;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Research\SourceCatalogMetadata;

/** Source identity and original labels survive mapping, including unknown cities/categories. */
final class PlaceMapper
{
    public function __construct(private readonly array $config = []) {}

    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    public function map(array $row, string $release, string $checkedAt): array
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || ! preg_match('~^(node|way|relation)/([1-9][0-9]*)$~D', $id, $identity)
            || ($row['country'] ?? null) !== 'IL' || ! is_array($row['tags'] ?? null)) {
            throw new RuntimeException('Invalid OSM identity, country or tags.');
        }
        $tags = $row['tags'];
        $first = static function (array $keys, int $limit = 500) use ($tags): ?string {
            foreach ($keys as $key) {
                if (($value = SourceCatalogMetadata::text($tags[$key] ?? null, $limit)) !== null) {
                    return $value;
                }
            }

            return null;
        };
        $name = $first(['name', 'name:he', 'name:en', 'name:ar', 'brand', 'operator'], 255) ?? '';
        $city = $first(['addr:city', 'addr:town', 'addr:village'], 120);
        $canonicalCity = $city;
        foreach ($this->config['sources']['osm_places']['city_names'] ?? [] as $canonical => $aliases) {
            foreach ([$canonical, ...(array) $aliases] as $alias) {
                if ($city !== null && mb_strtolower($alias) === mb_strtolower($city)) {
                    $canonicalCity = $canonical;
                }
            }
        }
        $categories = [];
        $mappedCategory = null;
        foreach (['shop', 'amenity', 'office', 'craft', 'healthcare', 'tourism', 'leisure'] as $key) {
            foreach (['', 'disused:', 'abandoned:', 'demolished:', 'razed:', 'removed:', 'construction:', 'proposed:'] as $prefix) {
                if (($value = $first([$prefix.$key], 120)) === null || in_array($value, ['no', 'yes'], true)) {
                    continue;
                }
                $sourceKey = $prefix.$key.'='.$value;
                $catalog = $this->config['sources']['osm_places']['category_map'][$key.'='.$value] ?? CategoryMapper::map($key.'='.$value);
                $categories[] = ['key' => $sourceKey, 'label' => str_replace('_', ' ', $value), 'catalog_key' => $catalog];
                if ($prefix === '') {
                    $mappedCategory ??= $catalog;
                }
            }
        }
        $hoursRaw = is_string($tags['opening_hours'] ?? null) ? trim($tags['opening_hours']) : null;
        $hoursRaw = $hoursRaw === '' ? null : ($hoursRaw === null ? null : mb_substr($hoursRaw, 0, 2048, 'UTF-8'));
        $hours = WeeklyOpeningHours::parse($hoursRaw);
        $metadata = [
            'source_id' => $id, 'osm_type' => $identity[1], 'osm_id' => $identity[2],
            'osm_version' => $row['version'] ?? null, 'osm_timestamp' => $row['timestamp'] ?? null,
            'country' => 'IL', 'country_filter' => $row['country_filter'] ?? null,
            'latitude' => $row['latitude'] ?? null, 'longitude' => $row['longitude'] ?? null,
            'geometry_method' => $row['geometry_method'] ?? null,
            'source_city' => $city, 'source_categories' => $categories,
            'opening_hours_raw' => $hoursRaw,
            'opening_hours_status' => $hoursRaw === null ? 'missing' : ($hours === [] ? 'raw_only' : 'weekly'),
            'lifecycle_status' => $row['lifecycle_status'] ?? 'active',
            'release' => $release, 'original_tags' => (object) $tags,
            'license_url' => 'https://opendatacommons.org/licenses/odbl/1-0/',
            'attribution' => '© OpenStreetMap contributors',
            'attribution_url' => 'https://www.openstreetmap.org/copyright',
        ];
        if (trim(BusinessNormalizer::cleanBusinessName($name)) === '' || ! preg_match('/[\pL\pN]/u', $name)) {
            $metadata['preparation_error'] = 'invalid_business_name';
        }
        $socials = [];
        foreach (['facebook', 'instagram', 'tiktok', 'telegram', 'x'] as $network) {
            $keys = $network === 'x' ? ['contact:twitter', 'twitter', 'contact:x'] : ['contact:'.$network, $network];
            if (($value = $first($keys, 2048)) !== null) {
                $socials[$network] = $value;
            }
        }

        return [
            'type' => 'business', 'name' => $name, 'category_key' => $mappedCategory,
            'address' => array_filter(['city' => $canonicalCity, 'street' => $first(['addr:street'], 255),
                'number' => $first(['addr:housenumber'], 40), 'neighborhood' => $first(['addr:suburb'], 120)], static fn ($v) => $v !== null),
            'public_description' => $first(['description', 'description:he', 'description:en'], 3000),
            'phone' => $first(['contact:phone', 'phone'], 40), 'contact_email' => $first(['contact:email', 'email'], 255),
            'website' => $first(['contact:website', 'website', 'url'], 2048),
            'whatsapp' => $first(['contact:whatsapp', 'whatsapp'], 80), 'socials' => $socials,
            'opening_hours' => $hours, 'source_name' => 'OpenStreetMap',
            'source_url' => 'https://www.openstreetmap.org/'.$id,
            'source_checked_at' => $checkedAt, 'source_metadata' => $metadata,
        ];
    }
}
