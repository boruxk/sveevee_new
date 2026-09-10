<?php

namespace App\Services\Sitemap;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class SitemapGenerator
{
    public const GENERATION_PATTERN = '/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/';

    public function generate(): array
    {
        $directory = rtrim((string) config('sitemap.directory'), '/\\');
        File::ensureDirectoryExists($directory.'/generations');
        $lock = fopen($directory.'/generate.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another sitemap generation is already running.');
        }
        $generation = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
        $building = $directory.'/generations/.building-'.$generation;
        $publishedDirectory = $directory.'/generations/'.$generation;
        $pointer = $directory.'/.current-'.$generation.'.json';
        $published = false;

        try {
            File::ensureDirectoryExists($building);
            $paths = new SitemapPathStore($building.'/paths.sqlite');
            $entries = new SitemapEntries($paths, max(1, min(1000, (int) config('sitemap.batch_size', 200))));
            $writer = new SitemapXmlWriter(
                $building, $generation, (string) config('app.url'),
                (int) config('sitemap.max_urls', SitemapXmlWriter::MAX_URLS),
                (int) config('sitemap.max_bytes', SitemapXmlWriter::MAX_BYTES),
            );
            $parts = [];
            foreach ([
                'static' => 'staticEntries', 'users' => 'userEntries', 'pages' => 'pageEntries',
                'products' => 'productEntries', 'ads' => 'adEntries', 'catalog' => 'catalogEntries', 'market' => 'marketEntries',
            ] as $family => $method) {
                foreach ($writer->write($family, $entries->{$method}()) as $part) {
                    $this->compress($building.'/'.$part['file']);
                    $part['file'] .= '.gz';
                    $parts[] = $part;
                }
            }
            unset($entries, $paths);
            File::delete($building.'/paths.sqlite');

            $this->writeFile($building.'/index.xml', $this->indexXml($parts));
            $manifest = [
                'generation' => $generation, 'generated_at' => gmdate(DATE_ATOM),
                'urls' => array_sum(array_column($parts, 'urls')),
                'bytes' => array_sum(array_column($parts, 'bytes')), 'parts' => $parts,
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $this->writeFile($building.'/manifest.json', $json);
            $this->writeFile($pointer, $json);
            if (! rename($building, $publishedDirectory)) {
                throw new RuntimeException('Could not finish the sitemap generation directory.');
            }
            // Switch the index only after every referenced file is complete.
            if (! rename($pointer, $directory.'/current.json')) {
                throw new RuntimeException('Could not publish the sitemap index.');
            }
            $published = true;
            try {
                $this->prune($directory, $generation);
            } catch (Throwable $error) {
                report($error);
            }

            return $manifest;
        } finally {
            unset($entries, $paths);
            File::delete($pointer);
            if (! $published) {
                foreach ([$building, $publishedDirectory] as $candidate) {
                    $this->removeGenerationDirectory($directory, $candidate);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function indexXml(array $parts): string
    {
        if (count($parts) > SitemapXmlWriter::MAX_URLS) {
            throw new RuntimeException('The sitemap index exceeds 50,000 child sitemaps.');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($parts as $part) {
            $xml .= '  <sitemap><loc>'.htmlspecialchars($part['loc'], ENT_XML1 | ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8')."</loc></sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";
        if (strlen($xml) > SitemapXmlWriter::MAX_BYTES) {
            throw new RuntimeException('The sitemap index exceeds the uncompressed byte limit.');
        }

        return $xml;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write the complete sitemap manifest.');
        }
    }

    private function compress(string $path): void
    {
        $input = fopen($path, 'rb');
        $output = gzopen($path.'.gz', 'wb6');
        if (! $input || ! $output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('Could not open sitemap compression streams.');
        }
        $closed = false;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false || gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not compress the complete sitemap.');
                }
            }
        } finally {
            fclose($input);
            $closed = gzclose($output);
        }
        if (! $closed) {
            throw new RuntimeException('Could not finish the compressed sitemap.');
        }
        File::delete($path);
    }

    private function prune(string $directory, string $current): void
    {
        $cutoff = time() - max(1, (int) config('sitemap.retain_days', 7)) * 86400;
        foreach (File::directories($directory.'/generations') as $candidate) {
            $name = basename($candidate);
            if ($name === $current || is_link($candidate)
                || ! preg_match('/\A(?:\.building-)?\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $name)) {
                continue;
            }
            if (filemtime($candidate) < $cutoff) {
                $this->removeGenerationDirectory($directory, $candidate);
            }
        }
    }

    private function removeGenerationDirectory(string $directory, string $candidate): void
    {
        $parent = realpath($directory.'/generations');
        $resolved = realpath($candidate);
        if ($parent !== false && $resolved !== false && ! is_link($candidate) && dirname($resolved) === $parent
            && preg_match('/\A(?:\.building-)?\d{8}T\d{6}Z-[a-f0-9]{12}\z/', basename($resolved))) {
            File::deleteDirectory($resolved);
        }
    }
}
