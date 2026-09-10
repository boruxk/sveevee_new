<?php

namespace Tests\Unit;

use App\Services\Sitemap\SitemapXmlWriter;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SitemapXmlWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitemap-writer-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_splits_a_lazy_sequence_at_the_url_limit_without_losing_entries(): void
    {
        $entries = (function () {
            for ($id = 1; $id <= 5; $id++) {
                yield $this->entry('/pages/'.$id);
            }
        })();

        $parts = $this->writer(maxUrls: 2)->write('pages', $entries);

        $this->assertSame([2, 2, 1], array_column($parts, 'urls'));
        $this->assertSame(['pages-0001', 'pages-0002', 'pages-0003'], array_column($parts, 'part'));
        $this->assertSame('https://sveevee.co.il/sitemap.xml?generation=test-generation&part=pages-0001', $parts[0]['loc']);

        $actualUrls = [];
        foreach ($parts as $part) {
            $xml = file_get_contents($this->directory.DIRECTORY_SEPARATOR.$part['file']);
            $this->assertSame(strlen($xml), $part['bytes']);
            $actualUrls = array_merge($actualUrls, $this->locations($xml));
        }

        $this->assertSame(array_map(fn ($id) => 'https://sveevee.co.il/pages/'.$id, range(1, 5)), $actualUrls);
    }

    public function test_exact_byte_boundary_includes_xml_wrapping_and_splits_before_the_next_url(): void
    {
        $entry = $this->entry('/עסקים?name=א&language=he', [[
            'loc' => 'https://sveevee.co.il/images/א.webp?a=1&b=2',
            'title' => 'A & B <עסק>',
            'caption' => 'שלום "עולם"',
        ]]);
        $reference = $this->writer()->write('reference', [$entry, $entry]);
        $exactBytes = $reference[0]['bytes'];

        $parts = $this->writer(maxBytes: $exactBytes)->write('pages', [$entry, $entry, $entry]);

        $this->assertSame([2, 1], array_column($parts, 'urls'));
        $this->assertSame($exactBytes, $parts[0]['bytes']);
        foreach ($parts as $part) {
            $xml = file_get_contents($this->directory.DIRECTORY_SEPARATOR.$part['file']);
            $this->assertLessThanOrEqual($exactBytes, strlen($xml));
            $this->assertSame(strlen($xml), $part['bytes']);
            $this->assertCount($part['urls'], $this->locations($xml));
        }
    }

    public function test_unicode_ampersands_images_and_invalid_xml_characters_are_handled(): void
    {
        $entry = $this->entry('/עסק?x=1&y=2', [[
            'loc' => 'https://sveevee.co.il/image.webp?x=1&y=2',
            'title' => "שלום & <world>\x00\x01",
            'caption' => "Picture\xC3\x28",
        ]]);

        $parts = $this->writer()->write('pages', [$entry]);
        $xml = file_get_contents($this->directory.DIRECTORY_SEPARATOR.$parts[0]['file']);
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        $this->assertSame($entry['loc'], $this->locations($xml)[0]);
        $this->assertSame('שלום & <world>', $xpath->evaluate('string(//image:title)'));
        $this->assertSame("Picture\u{FFFD}(", $xpath->evaluate('string(//image:caption)'));
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertSame(strlen($xml), $parts[0]['bytes']);
    }

    public function test_empty_sequence_creates_no_part_file(): void
    {
        $this->assertSame([], $this->writer()->write('users', []));
        $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'*.xml'));
    }

    public function test_exact_url_multiple_does_not_create_an_empty_trailing_part(): void
    {
        $parts = $this->writer(maxUrls: 2)->write('users', array_fill(0, 4, $this->entry('/users/1')));

        $this->assertSame([2, 2], array_column($parts, 'urls'));
        $this->assertCount(2, glob($this->directory.DIRECTORY_SEPARATOR.'*.xml'));
    }

    public function test_single_oversized_entry_fails_instead_of_disappearing(): void
    {
        $entry = $this->entry('/pages/1');
        $reference = $this->writer()->write('reference', [$entry]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('single sitemap URL exceeds');
        $this->writer(maxBytes: $reference[0]['bytes'] - 1)->write('pages', [$entry]);
    }

    public function test_source_exception_closes_the_current_file(): void
    {
        $entries = (function () {
            yield $this->entry('/pages/1');
            throw new RuntimeException('Source failed');
        })();

        try {
            $this->writer()->write('pages', $entries);
            $this->fail('The source exception should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Source failed', $exception->getMessage());
        }

        $this->assertTrue(unlink($this->directory.DIRECTORY_SEPARATOR.'pages-0001.xml'));
    }

    public function test_limits_cannot_exceed_the_protocol_caps_or_be_zero(): void
    {
        foreach ([[0, 1000], [50001, 1000], [1, 0], [1, 52428801]] as [$maxUrls, $maxBytes]) {
            try {
                $this->writer($maxUrls, $maxBytes);
                $this->fail('An invalid limit should be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_family_cannot_escape_the_output_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->writer()->write('../outside', [$this->entry('/pages/1')]);
    }

    private function writer(int $maxUrls = SitemapXmlWriter::MAX_URLS, int $maxBytes = SitemapXmlWriter::MAX_BYTES): SitemapXmlWriter
    {
        return new SitemapXmlWriter($this->directory, 'test-generation', 'https://sveevee.co.il', $maxUrls, $maxBytes);
    }

    private function entry(string $path, array $images = []): array
    {
        return [
            'loc' => 'https://sveevee.co.il'.$path,
            'lastmod' => '2026-09-10',
            'changefreq' => 'weekly',
            'priority' => '0.8',
            'images' => $images,
        ];
    }

    private function locations(string $xml): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('sitemap', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('/sitemap:urlset/sitemap:url/sitemap:loc')));
    }
}
