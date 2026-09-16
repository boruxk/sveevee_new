<?php

namespace Tests\Feature;

use App\Services\SeoPrerenderService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SeoPrerenderSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    private string $dist;

    private const SHELL = '<!doctype html><html lang="he"><head><meta charset="UTF-8"><title>Generic shell</title></head><body><div id="app"></div><script type="module" src="/assets/app-fixture.js"></script></body></html>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = storage_path('framework/testing/seo-prerender-safety-'.bin2hex(random_bytes(8)));
        $this->dist = $this->fixtureRoot.'/dist';
        $this->putFixture('dist/index.html', self::SHELL);
        config(['app.url' => 'https://sveevee.co.il', 'seo.frontend_dist' => $this->dist]);

        $this->beforeApplicationDestroyed(function (): void {
            $base = realpath(storage_path('framework/testing'));
            $target = realpath($this->fixtureRoot);
            if ($base === false || $target === false || is_link($this->fixtureRoot)
                || ! str_starts_with(strtolower($target.DIRECTORY_SEPARATOR), strtolower($base.DIRECTORY_SEPARATOR))
                || ! preg_match('/\Aseo-prerender-safety-[a-f0-9]{16}\z/', basename($target))) {
                throw new RuntimeException('Refusing unsafe test fixture cleanup.');
            }
            // Filesystem::deleteDirectory unlinks child symlinks without following them.
            (new Filesystem)->deleteDirectory($target);
        });
    }

    public static function invalidShells(): array
    {
        return [
            'empty build' => [''],
            'missing app mount' => ['<!doctype html><html><head><title>Incomplete</title></head><body></body></html>'],
            'missing document head' => ['<html><body><div id="app"></div></body></html>'],
        ];
    }

    #[DataProvider('invalidShells')]
    public function test_invalid_frontend_shell_returns_retryable_homepage_failure(string $shell): void
    {
        $this->putFixture('dist/index.html', $shell);

        $response = $this->get('/')->assertStatus(503)->assertHeader('Retry-After', '300');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNotSame('', $response->getContent());
    }

    public function test_frontend_read_failure_is_a_retryable_response(): void
    {
        $filesystem = new Filesystem;
        File::partialMock()->shouldReceive('get')->andReturnUsing(function ($path, $lock = false) use ($filesystem) {
            if (str_replace('\\', '/', $path) === str_replace('\\', '/', $this->dist.'/index.html')) {
                throw new FileNotFoundException('The frontend build changed before it could be read.');
            }

            return $filesystem->get($path, $lock);
        });

        $this->get('/')->assertStatus(503)->assertHeader('Retry-After', '300');
    }

    public function test_interrupted_entry_promotion_preserves_previous_manifest_and_record_files(): void
    {
        $oldRecord = $this->putFixture('dist/he/business/old-business-100/index.html', 'Previous business HTML');
        $oldManifest = json_encode([
            'generated_at' => '2026-09-01T00:00:00Z',
            'files' => ['he/business/old-business-100/index.html'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestPath = $this->putFixture('dist/.sveevee-prerender.json', $oldManifest);
        $filesystem = new Filesystem;
        $replacements = 0;
        File::partialMock()->shouldReceive('replace')->andReturnUsing(function ($path, $contents, $mode = null) use ($filesystem, &$replacements): void {
            if (++$replacements === 2) {
                throw new RuntimeException('Simulated second promotion failure.');
            }
            $filesystem->replace($path, $contents, $mode);
        });

        try {
            app(SeoPrerenderService::class)->render($this->dist);
            $this->fail('The simulated promotion failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated second promotion failure.', $exception->getMessage());
        }

        $this->assertSame(2, $replacements);
        $this->assertSame($oldManifest, file_get_contents($manifestPath));
        $this->assertSame('Previous business HTML', file_get_contents($oldRecord));
        $this->assertSame(self::SHELL, file_get_contents($this->dist.'/index.html'));
        $this->assertSame([], glob($this->dist.'/.sveevee-prerender-*') ?: []);
    }

    public static function outputSymlinks(): array
    {
        return ['generated file' => ['file'], 'parent directory' => ['directory'], 'manifest' => ['manifest']];
    }

    #[DataProvider('outputSymlinks')]
    public function test_prerender_rejects_symlinks_outside_dist_without_touching_the_target(string $kind): void
    {
        $outsideFile = $this->putFixture('outside/protected.html', 'Outside sentinel');
        $oldManifest = json_encode(['files' => []], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->putFixture('dist/.sveevee-prerender.json', $oldManifest);

        if ($kind === 'directory') {
            $target = dirname($outsideFile);
            $link = $this->dist.'/catalog';
        } elseif ($kind === 'manifest') {
            $target = $outsideFile;
            $link = $this->dist.'/.sveevee-prerender.json';
            unlink($link);
        } else {
            $target = $outsideFile;
            $link = $this->dist.'/businesses/index.html';
            (new Filesystem)->ensureDirectoryExists(dirname($link));
        }

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('This local environment does not permit creating symlinks.');
        }

        try {
            app(SeoPrerenderService::class)->render($this->dist);
            $this->fail('A symlink escaping frontend dist must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame('Outside sentinel', file_get_contents($outsideFile));
        $this->assertSame(['protected.html'], array_values(array_diff(scandir(dirname($outsideFile)), ['.', '..'])));
        $this->assertSame([], glob($this->dist.'/.sveevee-prerender-*') ?: []);
        if ($kind !== 'manifest') {
            $this->assertSame($oldManifest, file_get_contents($this->dist.'/.sveevee-prerender.json'));
        }
    }

    private function putFixture(string $relative, string $contents): string
    {
        $path = $this->fixtureRoot.'/'.$relative;
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        return $path;
    }
}
