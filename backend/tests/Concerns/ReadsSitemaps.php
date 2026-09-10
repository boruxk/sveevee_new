<?php

namespace Tests\Concerns;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ReadsSitemaps
{
    private ?string $sitemapTestDirectory = null;

    protected function prepareSitemapDirectory(): string
    {
        if ($this->sitemapTestDirectory === null) {
            $directory = storage_path('framework/testing/sitemaps-'.bin2hex(random_bytes(8)));
            $this->sitemapTestDirectory = $directory;
            $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
        }

        config()->set('sitemap.directory', $this->sitemapTestDirectory);

        return $this->sitemapTestDirectory;
    }

    /**
     * Follow the sitemap index and combine its URL sets for content assertions.
     * Each real response is checked before building the combined test response.
     */
    protected function getSitemapUrlsets(string $path = '/sitemap.xml'): TestResponse
    {
        $this->prepareSitemapDirectory();
        $this->artisan('sitemap:generate')->assertSuccessful();
        $pending = [$path];
        $visited = [];
        $urlsets = [];

        while ($pending !== []) {
            $current = array_shift($pending);
            $this->assertArrayNotHasKey($current, $visited, 'The sitemap index contains a duplicate or circular link.');
            $visited[$current] = true;

            $response = $this->get($current)
                ->assertOk()
                ->assertHeader('content-type', 'application/xml; charset=UTF-8');
            $content = $this->sitemapResponseContent($response);
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
            $this->assertNotFalse($xml, 'Sitemap response is not valid XML: '.$current);

            if ($xml->getName() === 'urlset') {
                $urlsets[] = $content;

                continue;
            }

            $this->assertSame('sitemapindex', $xml->getName());
            $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            foreach ($xml->xpath('/sm:sitemapindex/sm:sitemap/sm:loc') as $location) {
                $parts = parse_url((string) $location);
                $this->assertIsArray($parts);
                $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), $parts['host'] ?? null);
                $pending[] = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return TestResponse::fromBaseResponse(new Response(implode("\n", $urlsets), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]));
    }

    protected function sitemapResponseContent(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse || $response->baseResponse instanceof BinaryFileResponse) {
            return $response->streamedContent();
        }

        return $response->getContent();
    }
}
