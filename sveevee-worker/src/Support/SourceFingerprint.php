<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

use Sveevee\Worker\Research\SourceCatalogMetadata;

final class SourceFingerprint
{
    public static function hash(array $raw): string
    {
        // Audit metadata can change between releases without changing the business itself.
        unset($raw['source_checked_at'], $raw['source_metadata']);

        return Json::hash($raw);
    }

    /** Compare business facts across prepared releases, excluding snapshot/audit timestamps. */
    public static function snapshotHash(array $raw, string $adapter): string
    {
        $metadata = is_array($raw['source_metadata'] ?? null) ? $raw['source_metadata'] : [];
        unset($raw['source_checked_at'], $raw['source_metadata']);
        if ($adapter === 'overture_places') {
            $metadata = array_replace($metadata, SourceCatalogMetadata::overture($metadata));
            $metadata = array_intersect_key($metadata, array_flip([
                'gers_id', 'overture_id', 'operating_status', 'taxonomy', 'address', 'sources', 'bbox',
                'geometry', 'basic_category', 'names', 'phones', 'websites', 'emails', 'socials', 'brand',
                'source_city', 'source_categories',
            ]));
            if (is_array($metadata['sources'] ?? null)) {
                $metadata['sources'] = array_map(static function (mixed $source): mixed {
                    if (is_array($source)) {
                        unset($source['update_time'], $source['confidence'], $source['version']);
                    }

                    return $source;
                }, $metadata['sources']);
                usort($metadata['sources'], static fn (mixed $a, mixed $b): int => strcmp(Json::hash($a), Json::hash($b)));
            }
        } elseif ($adapter === 'foursquare_places') {
            $metadata = array_intersect_key($metadata, array_flip([
                'source_id', 'country', 'date_closed', 'latitude', 'longitude', 'source_city',
                'source_categories', 'original_record', 'preparation_error',
            ]));
            if (is_array($metadata['original_record'] ?? null)) {
                unset($metadata['original_record']['date_created'], $metadata['original_record']['date_refreshed']);
            }
        } else {
            throw new \InvalidArgumentException('Snapshot comparison is only available for Overture and Foursquare.');
        }
        $raw['source_metadata'] = $metadata;

        return Json::hash($raw);
    }
}
