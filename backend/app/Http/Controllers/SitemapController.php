<?php

namespace App\Http\Controllers;

use App\Services\Sitemap\SitemapGenerator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    public function index(Request $request): Response
    {
        $directory = rtrim((string) config('sitemap.directory'), '/\\');
        $part = $request->query('part');
        $generation = $request->query('generation');
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];

        if ($generation === null) {
            if (! is_file($directory.'/current.json')) {
                return response('Sitemap is being prepared.', 503)->header('Retry-After', '300');
            }
            $manifest = json_decode(file_get_contents($directory.'/current.json'), true, 512, JSON_THROW_ON_ERROR);
            $generation = $manifest['generation'] ?? '';
            abort_unless(is_string($generation) && preg_match(SitemapGenerator::GENERATION_PATTERN, $generation), 503);

            if ($part === null) {
                $response = response()->file($directory.'/generations/'.$generation.'/index.xml', $headers + [
                    'Cache-Control' => 'public, max-age=300',
                ]);
                $response->isNotModified($request);

                return $response;
            }
        }

        abort_unless(is_string($generation) && preg_match(SitemapGenerator::GENERATION_PATTERN, $generation)
            && is_string($part) && preg_match('/\A(?:static|users|pages|products|ads|catalog|market)-\d{4,5}\z/', $part), 404);
        $generationDirectory = $directory.'/generations/'.$generation;
        abort_unless(is_file($generationDirectory.'/manifest.json'), 404);
        $manifest = json_decode(file_get_contents($generationDirectory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $entry = collect($manifest['parts'] ?? [])->firstWhere('part', $part);
        $path = $generationDirectory.'/'.$part.'.xml.gz';
        abort_unless($entry && is_file($path), 404);

        // Store compressed files but serve ordinary XML without loading the whole part.
        $response = response()->stream(function () use ($path): void {
            $stream = gzopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Could not read the generated sitemap.');
            }
            try {
                while (! gzeof($stream)) {
                    $chunk = gzread($stream, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Could not read the complete generated sitemap.');
                    }
                    echo $chunk;
                }
            } finally {
                gzclose($stream);
            }
        }, 200, $headers + [
            'Content-Length' => (string) $entry['bytes'],
            'Cache-Control' => 'public, max-age=3600',
        ]);
        $response->setEtag($generation.'-'.$part);
        $response->isNotModified($request);

        return $response;
    }
}
