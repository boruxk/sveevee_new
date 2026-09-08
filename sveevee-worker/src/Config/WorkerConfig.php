<?php

declare(strict_types=1);

namespace Sveevee\Worker\Config;

use RuntimeException;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;

final class WorkerConfig
{
    private function __construct(
        private readonly array $data,
        public readonly string $path,
        public readonly string $root,
    ) {}

    public static function load(string $path, string $root): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Worker config not found: {$path}");
        }

        $decoded = Json::decode((string) file_get_contents($path));
        if (! is_array($decoded)) {
            throw new RuntimeException('Worker config must contain a JSON object.');
        }

        $config = new self($decoded, realpath($path) ?: $path, $root);
        $config->validate();

        return $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function targets(): array
    {
        $targets = [];
        $neighborhoods = $this->get('neighborhoods', []);

        foreach ($this->get('cities', []) as $city) {
            $cityNeighborhoods = is_array($neighborhoods) && ! array_is_list($neighborhoods)
                ? ($neighborhoods[$city] ?? [])
                : $neighborhoods;
            $cityNeighborhoods = is_array($cityNeighborhoods) ? $cityNeighborhoods : [];
            $locations = $cityNeighborhoods === [] ? [null] : $cityNeighborhoods;

            foreach ($this->get('categories', []) as $category) {
                foreach ($locations as $neighborhood) {
                    $targets[] = new ResearchTarget(
                        trim((string) $city),
                        trim((string) $category),
                        is_string($neighborhood) && trim($neighborhood) !== '' ? trim($neighborhood) : null,
                    );
                }
            }
        }

        return $targets;
    }

    public function resolvePath(string $configured, ?string $environmentName = null): string
    {
        if ($environmentName !== null && Environment::get($environmentName) !== null) {
            return (string) Environment::get($environmentName);
        }
        if (self::isAbsolutePath($configured)) {
            return $configured;
        }

        return rtrim($this->root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
    }

    public function source(string $name): array
    {
        $value = $this->get('sources.'.$name, []);

        return is_array($value) ? $value : [];
    }

    public function hash(): string
    {
        return Json::hash($this->data);
    }

    private function validate(): void
    {
        $target = $this->int('target_per_run', 1000);
        $batch = $this->int('batch_size', 100);
        if ($target < 1) {
            throw new RuntimeException('target_per_run must be at least 1.');
        }
        if ($batch < 1 || $batch > 100) {
            throw new RuntimeException('batch_size must be between 1 and 100.');
        }
        if ($this->targets() === []) {
            throw new RuntimeException('Configure at least one city and category.');
        }
        foreach (['storage.database', 'storage.reports_dir', 'storage.log_file'] as $path) {
            if (! is_string($this->get($path)) || trim((string) $this->get($path)) === '') {
                throw new RuntimeException("Missing config value: {$path}");
            }
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}

