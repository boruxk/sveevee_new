<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Overture;

use RuntimeException;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Support\Json;

/** Resolves all intersecting partitions before exporting; partial catalogs are fatal. */
final class ReleaseCatalog
{
    public function __construct(private readonly HttpClientInterface $http) {}

    public function latest(): string
    {
        $catalog = $this->fetch('https://stac.overturemaps.org/catalog.json');
        $releases = [];
        foreach ($catalog['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['self', 'root', 'latest-version', 'child'], true)
                && preg_match('~^https://stac\.overturemaps\.org/(\d{4}-\d{2}-\d{2}\.\d+)/catalog\.json$~D', $link['href'] ?? '', $match)) {
                self::validateRelease($match[1]);
                $releases[] = $match[1];
            }
        }
        if ($releases !== []) {
            usort($releases, version_compare(...));

            return $releases[count($releases) - 1];
        }
        throw new RuntimeException('Cannot determine the current Overture release from the official STAC catalog; pass --release=YYYY-MM-DD.N.');
    }

    public function files(string $release): array
    {
        self::validateRelease($release);
        $prefix = 'https://stac.overturemaps.org/'.$release.'/places/place/';
        $collection = $this->fetch($prefix.'collection.json');
        if (($collection['type'] ?? null) !== 'Collection' || ($collection['id'] ?? null) !== 'place') {
            throw new RuntimeException('Unexpected Overture Places STAC collection.');
        }
        $items = [];
        foreach ($collection['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') !== 'item') {
                continue;
            }
            $url = $link['href'] ?? '';
            if (! is_string($url) || ! str_starts_with($url, $prefix) || ! str_ends_with($url, '.json') || str_contains($url, '..')) {
                throw new RuntimeException('Unexpected Overture STAC partition URL.');
            }
            $items[$url] = true;
        }
        if ($items === [] || count($items) > 1000) {
            throw new RuntimeException('Overture STAC partition count is outside the supported range.');
        }
        $files = [];
        foreach (array_keys($items) as $url) {
            $item = $this->fetch($url);
            $bbox = $item['bbox'] ?? null;
            if (($item['type'] ?? null) !== 'Feature' || ! is_array($bbox) || count($bbox) !== 4 || count(array_filter($bbox, is_numeric(...))) !== 4) {
                throw new RuntimeException('Missing or invalid Overture partition bounding box.');
            }
            if ($bbox[0] > 36 || $bbox[2] < 34 || $bbox[1] > 34 || $bbox[3] < 29) {
                continue;
            }
            $asset = $item['assets']['aws']['href'] ?? '';
            $assetPrefix = 'https://overturemaps-us-west-2.s3.us-west-2.amazonaws.com/release/'.$release.'/theme=places/type=place/';
            if (! is_string($asset) || ! str_starts_with($asset, $assetPrefix) || ! str_ends_with($asset, '.parquet') || str_contains($asset, '..') || str_contains($asset, '?')) {
                throw new RuntimeException('Unexpected Overture HTTPS Parquet asset.');
            }
            $files[$asset] = true;
        }
        if ($files === []) {
            throw new RuntimeException('Overture STAC has no partition covering Israel.');
        }

        return array_keys($files);
    }

    public static function validateRelease(string $release): void
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})\.\d+$/D', $release, $match)
            || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw new RuntimeException('Overture release must use YYYY-MM-DD.N.');
        }
    }

    private function fetch(string $url): array
    {
        $response = $this->http->request('GET', $url, ['Accept' => 'application/json'], null, ['timeout' => 45, 'max_bytes' => 4 * 1024 * 1024]);
        if ($response->status !== 200) {
            throw new RuntimeException('Overture STAC request failed with HTTP '.$response->status.'.');
        }
        $result = Json::decode($response->body);
        if (! is_array($result)) {
            throw new RuntimeException('Overture STAC did not return a JSON object.');
        }

        return $result;
    }
}
