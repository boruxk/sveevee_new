<?php

namespace App\Services\Sitemap;

use InvalidArgumentException;
use RuntimeException;

class SitemapXmlWriter
{
    public const MAX_URLS = 50000;

    public const MAX_BYTES = 52428800;

    private const HEADER = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

    private const FOOTER = "</urlset>\n";

    public function __construct(
        private readonly string $directory,
        private readonly string $generation,
        private readonly string $baseUrl,
        private readonly int $maxUrls = self::MAX_URLS,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
        if ($maxUrls < 1 || $maxUrls > self::MAX_URLS) {
            throw new InvalidArgumentException('Sitemap URL limit must be between 1 and 50,000.');
        }

        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('Sitemap byte limit must be between 1 and 52,428,800.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the sitemap output directory.');
        }
    }

    /**
     * @param  iterable<array{loc: string, lastmod: string, changefreq: string, priority: string, images?: array}>  $entries
     * @return array<array{part: string, file: string, loc: string, urls: int, bytes: int}>
     */
    public function write(string $family, iterable $entries): array
    {
        if (! preg_match('/\A[a-z][a-z0-9-]*\z/', $family)) {
            throw new InvalidArgumentException('Sitemap family must be a lowercase name containing only letters, digits, and hyphens.');
        }

        $parts = [];
        $handle = null;
        $part = null;
        $urls = 0;
        $bytes = 0;
        $overhead = strlen(self::HEADER) + strlen(self::FOOTER);

        try {
            foreach ($entries as $entry) {
                $xml = $this->entryXml($entry);
                $entryBytes = strlen($xml);

                if ($overhead + $entryBytes > $this->maxBytes) {
                    throw new RuntimeException('A single sitemap URL exceeds the configured uncompressed byte limit.');
                }

                if (is_resource($handle) && ($urls >= $this->maxUrls || $bytes + $entryBytes + strlen(self::FOOTER) > $this->maxBytes)) {
                    $parts[] = $this->finish($handle, $part, $urls, $bytes);
                    $handle = null;
                }

                if (! is_resource($handle)) {
                    $part = $family.'-'.str_pad((string) (count($parts) + 1), 4, '0', STR_PAD_LEFT);
                    $path = rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$part.'.xml';
                    $handle = fopen($path, 'xb');

                    if ($handle === false) {
                        throw new RuntimeException('Could not open the sitemap output file.');
                    }

                    $this->append($handle, self::HEADER);
                    $urls = 0;
                    $bytes = strlen(self::HEADER);
                }

                $this->append($handle, $xml);
                $urls++;
                $bytes += $entryBytes;
            }

            if (is_resource($handle)) {
                $parts[] = $this->finish($handle, $part, $urls, $bytes);
                $handle = null;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $parts;
    }

    private function entryXml(array $entry): string
    {
        $xml = "  <url>\n";

        foreach (['loc', 'lastmod', 'changefreq', 'priority'] as $field) {
            $xml .= '    <'.$field.'>'.$this->escape((string) ($entry[$field] ?? '')).'</'.$field.">\n";
        }

        foreach ($entry['images'] ?? [] as $image) {
            $xml .= "    <image:image>\n";
            $xml .= '      <image:loc>'.$this->escape((string) $image['loc'])."</image:loc>\n";

            foreach (['title', 'caption'] as $field) {
                if (isset($image[$field]) && trim((string) $image[$field]) !== '') {
                    $xml .= '      <image:'.$field.'>'.$this->escape((string) $image[$field]).'</image:'.$field.">\n";
                }
            }

            $xml .= "    </image:image>\n";
        }

        return $xml."  </url>\n";
    }

    private function escape(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $escaped) ?? '';
    }

    /** @param resource $handle */
    private function append($handle, string $value): void
    {
        $length = strlen($value);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($handle, substr($value, $written));

            if ($result === false || $result === 0) {
                throw new RuntimeException('Could not write the complete sitemap output.');
            }

            $written += $result;
        }
    }

    /** @param resource $handle */
    private function finish($handle, string $part, int $urls, int $bytes): array
    {
        $this->append($handle, self::FOOTER);

        if (! fflush($handle)) {
            throw new RuntimeException('Could not flush the sitemap output file.');
        }

        fclose($handle);

        return [
            'part' => $part,
            'file' => $part.'.xml',
            'loc' => rtrim($this->baseUrl, '/').'/sitemap.xml?generation='.rawurlencode($this->generation).'&part='.rawurlencode($part),
            'urls' => $urls,
            'bytes' => $bytes + strlen(self::FOOTER),
        ];
    }
}
