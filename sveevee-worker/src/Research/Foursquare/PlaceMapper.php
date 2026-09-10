<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Foursquare;

use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Research\SourceCatalogMetadata;

/** Maps the official open Places schema without inventing missing localities or classifications. */
final class PlaceMapper
{
    private array $cities = [];

    public function __construct(array $cities = [], array $aliases = [])
    {
        foreach ($cities as $city) {
            foreach ([$city, ...($aliases[$city] ?? [])] as $alias) {
                $key = mb_strtolower(trim((string) $alias), 'UTF-8');
                if (isset($this->cities[$key]) && $this->cities[$key] !== $city) {
                    throw new RuntimeException('Ambiguous Foursquare city alias.');
                }
                $this->cities[$key] = $city;
            }
        }
    }

    public static function fromConfig(array $config): self
    {
        $aliases = ['Tel Aviv' => ['Tel Aviv-Yafo']];
        foreach ($config['sources']['data_gov_ckan']['datasets'] ?? [] as $dataset) {
            if (($dataset['profile'] ?? null) === 'israel_companies') {
                foreach ($dataset['city_names'] ?? [] as $city => $names) {
                    $aliases[$city] = [...($aliases[$city] ?? []), ...(array) $names];
                }
            }
        }
        foreach ($config['sources']['foursquare_places']['city_names'] ?? [] as $city => $names) {
            $aliases[$city] = [...($aliases[$city] ?? []), ...(array) $names];
        }

        return new self($config['cities'] ?? [], $aliases);
    }

    public function map(array $row, string $release, string $checkedAt, ?string &$skipReason = null): ?array
    {
        $skipReason = null;
        foreach (['fsq_place_id', 'name', 'country', 'locality', 'address', 'fsq_category_ids', 'fsq_category_labels', 'date_closed'] as $key) {
            if (! array_key_exists($key, $row)) {
                throw new RuntimeException('Foursquare record is missing required schema field '.$key.'.');
            }
        }
        $id = $row['fsq_place_id'];
        if (! is_string($id) || preg_match('/^[0-9a-f]{24}$/D', $id) !== 1) {
            throw new RuntimeException('Foursquare record has an invalid source ID.');
        }
        if ($row['country'] !== 'IL') {
            $skipReason = 'country';

            return null;
        }
        $name = SourceCatalogMetadata::text($row['name'], 255);
        $invalidName = $name === null || BusinessNormalizer::cleanBusinessName($name) === '' || ! preg_match('/[\p{L}\p{N}]/u', $name);
        foreach (['fsq_category_ids', 'fsq_category_labels'] as $field) {
            if ($row[$field] !== null && (! is_array($row[$field]) || ! array_is_list($row[$field]))) {
                throw new RuntimeException('Foursquare '.$field.' must be an array or null.');
            }
        }
        $categories = CategoryMapper::descriptors($row);
        $category = null;
        foreach ($categories as $descriptor) {
            $category ??= $descriptor['catalog_key'];
        }
        $rawCity = SourceCatalogMetadata::text($row['locality'], 120);
        $city = $rawCity === null ? null : ($this->cities[mb_strtolower($rawCity, 'UTF-8')] ?? $rawCity);
        $normalizer = new BusinessNormalizer(new OpeningHoursParser, array_values(array_unique($this->cities)));
        $sourceUrl = 'https://foursquare.com/placemakers/review-place/'.$id;
        $socials = array_filter([
            'facebook' => SourceCatalogMetadata::text($row['facebook_id'] ?? null, 2048),
            'instagram' => SourceCatalogMetadata::text($row['instagram'] ?? null, 2048),
            'x' => SourceCatalogMetadata::text($row['twitter'] ?? null, 2048),
        ], static fn ($value): bool => $value !== null);
        $metadata = [
            'source_id' => $id, 'fsq_place_id' => $id, 'release' => $release,
            'country' => 'IL', 'date_created' => $row['date_created'] ?? null,
            'date_refreshed' => $row['date_refreshed'] ?? null, 'date_closed' => $row['date_closed'],
            'latitude' => $row['latitude'] ?? null, 'longitude' => $row['longitude'] ?? null,
            'source_city' => $rawCity, 'source_categories' => $categories,
            'original_record' => $row,
            'license_url' => 'https://www.apache.org/licenses/LICENSE-2.0',
            'attribution' => 'Foursquare Open Source Places — Copyright Foursquare Labs, Inc.',
            'notice_url' => 'https://opensource.foursquare.com/places-notice-txt/',
        ];
        if ($invalidName) {
            $metadata['preparation_error'] = 'invalid_business_name';
        }
        $candidate = $normalizer->normalize([
            // The temporary name only lets optional fields share the normalizer; it is never stored or published.
            'name' => $invalidName ? 'Foursquare '.$id : $name, 'category_key' => $category,
            'address' => ['city' => $city, 'street' => SourceCatalogMetadata::text($row['address'], 255)],
            'phone' => $row['tel'] ?? null, 'contact_email' => $row['email'] ?? null,
            'website' => $row['website'] ?? null, 'socials' => $socials,
            'source_url' => $sourceUrl, 'source_checked_at' => $checkedAt,
            'source_metadata' => $metadata,
        ], ResearchTarget::sourceAll('foursquare_places'), 'foursquare_places');
        $data = $candidate->data;

        return [
            'id' => $id, 'name' => $invalidName ? (is_string($row['name']) ? $row['name'] : '') : $data['name'], 'category_key' => $data['category_key'] ?? null,
            'city' => $data['address']['city'] ?? null, 'street' => $data['address']['street'] ?? null,
            'phone' => $data['phone'] ?? null, 'email' => $data['contact_email'] ?? null,
            'website' => $data['website'] ?? null, 'social_links' => (object) ($data['socials'] ?? []),
            'confidence' => null, 'release' => $release, 'source_url' => $sourceUrl,
            'source_name' => 'Foursquare Open Source Places', 'source_checked_at' => $checkedAt,
            'source_metadata' => $metadata,
        ];
    }
}
