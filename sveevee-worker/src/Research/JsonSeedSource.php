<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Support\Json;

final class JsonSeedSource implements SourceAdapterInterface
{
    public function __construct(
        private readonly array $config,
        private readonly string $root,
    ) {}

    public function name(): string
    {
        return 'json_seed';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 30));
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        $emitted = 0;
        foreach ((array) ($this->config['paths'] ?? []) as $configuredPath) {
            $path = $this->absolutePath((string) $configuredPath);
            foreach ($this->read($path) as $index => $business) {
                if (! is_array($business) || ! $this->matches($business, $target)) {
                    continue;
                }
                $business['source_name'] ??= 'json_seed';
                $business['source_checked_at'] ??= gmdate(DATE_ATOM, (int) (filemtime($path) ?: time()));
                $business['_seed_reference'] = basename($path).'#'.($index + 1);
                yield $business;
                $emitted++;
                if ($emitted >= $limit) {
                    return;
                }
            }
        }
    }

    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("JSON seed file not found: {$path}");
        }
        $contents = (string) file_get_contents($path);
        try {
            $decoded = Json::decode($contents);
            if (is_array($decoded) && isset($decoded['businesses']) && is_array($decoded['businesses'])) {
                return $decoded['businesses'];
            }
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        } catch (\JsonException) {
            $rows = [];
            foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
                if (trim($line) !== '') {
                    $rows[] = Json::decode($line);
                }
            }

            return $rows;
        }

        throw new RuntimeException("JSON seed must be an array, JSONL, or an object with businesses: {$path}");
    }

    private function matches(array $business, ResearchTarget $target): bool
    {
        if (isset($business['category_key']) && $business['category_key'] !== $target->categoryKey) {
            return false;
        }
        $address = is_array($business['address'] ?? null) ? $business['address'] : [];
        if (isset($address['city']) && strcasecmp((string) $address['city'], $target->city) !== 0) {
            return false;
        }
        if ($target->neighborhood !== null
            && isset($address['neighborhood'])
            && strcasecmp((string) $address['neighborhood'], $target->neighborhood) !== 0) {
            return false;
        }

        return true;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim($this->root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}

