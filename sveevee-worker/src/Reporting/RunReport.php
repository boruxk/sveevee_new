<?php

declare(strict_types=1);

namespace Sveevee\Worker\Reporting;

use RuntimeException;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Json;

final class RunReport
{
    private readonly float $startedTimer;

    private readonly string $startedAt;

    private array $metrics = [
        'found' => 0,
        'new' => 0,
        'existing' => 0,
        'updated' => 0,
        'duplicates' => 0,
        'incomplete' => 0,
        'failed' => 0,
        'imported' => 0,
        'planned_imports' => 0,
        'planned_updates' => 0,
    ];

    private array $sources = [];

    private array $targets = [];

    private array $errors = [];

    private bool $sourceMetricsEnabled = false;

    private ?array $overtureProgress = null;

    private ?array $foursquareProgress = null;

    public function foursquareProgress(?array $progress): void
    {
        $this->foursquareProgress = $progress;
    }

    public function overtureProgress(?array $progress): void
    {
        $this->overtureProgress = $progress;
    }

    public function __construct(
        public readonly string $runId,
        public readonly string $command,
        public readonly bool $dryRun,
    ) {
        $this->startedTimer = microtime(true);
        $this->startedAt = Clock::now();
    }

    public function increment(string $metric, int $amount = 1): void
    {
        $this->metrics[$metric] = ($this->metrics[$metric] ?? 0) + $amount;
    }

    public function metric(string $name): int
    {
        return $this->metrics[$name] ?? 0;
    }

    public function source(string $name, int $amount = 1): void
    {
        $this->sources[$name] = ($this->sources[$name] ?? 0) + $amount;
    }

    public function enableSourceMetrics(): void
    {
        $this->sourceMetricsEnabled = true;
        $this->metrics['source_requests'] ??= 0;
        $this->metrics['source_errors'] ??= 0;
    }

    public function target(string $key, string $city, string $categoryKey, int $found, int $successful = 0, int $planned = 0): void
    {
        $previous = $this->targets[$key] ?? [];
        $this->targets[$key] = [
            'key' => $key,
            'city' => $city,
            'category_key' => $categoryKey,
            'found' => ($previous['found'] ?? 0) + $found,
            'successful' => ($previous['successful'] ?? 0) + $successful,
            'planned' => ($previous['planned'] ?? 0) + $planned,
            ...(isset($previous['deferred']) ? ['deferred' => $previous['deferred']] : []),
        ];
    }

    public function deferTarget(string $key, string $city, string $categoryKey): void
    {
        $this->target($key, $city, $categoryKey, 0);
        $this->targets[$key]['deferred'] = true;
    }

    public function error(string $stage, string $message, array $context = []): void
    {
        if (count($this->errors) < 200) {
            $this->errors[] = [
                'stage' => $stage,
                'message' => $message,
                'context' => $context,
            ];
        }
    }

    public function toArray(string $status = 'completed'): array
    {
        ksort($this->sources);
        $productive = count(array_filter($this->targets, fn (array $target): bool => ($this->dryRun ? $target['planned'] : $target['successful']) > 0));
        $empty = count(array_filter($this->targets, static fn (array $target): bool => ! ($target['deferred'] ?? false) && $target['found'] === 0 && $target['successful'] === 0 && $target['planned'] === 0));
        $deferred = count(array_filter($this->targets, static fn (array $target): bool => $target['deferred'] ?? false));

        return [
            'run_id' => $this->runId,
            'command' => $this->command,
            'dry_run' => $this->dryRun,
            'status' => $status,
            'started_at' => $this->startedAt,
            'finished_at' => Clock::now(),
            'duration_seconds' => round(microtime(true) - $this->startedTimer, 3),
            ...$this->metrics,
            'target_combinations' => count($this->targets),
            'scanned_target_combinations' => count($this->targets),
            'productive_target_combinations' => $productive,
            'empty_target_combinations' => $empty,
            'unproductive_target_combinations' => count($this->targets) - $productive,
            ...($this->sourceMetricsEnabled ? ['deferred_target_combinations' => $deferred] : []),
            'targets' => array_values($this->targets),
            'used_sources' => array_keys($this->sources),
            'source_counts' => $this->sources,
            'errors' => $this->errors,
            ...($this->overtureProgress === null ? [] : ['overture_progress' => $this->overtureProgress]),
            ...($this->foursquareProgress === null ? [] : ['foursquare_progress' => $this->foursquareProgress]),
        ];
    }

    public function write(string $directory, string $status = 'completed'): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create reports directory: {$directory}");
        }
        $report = $this->toArray($status);
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR
            .gmdate('Ymd-His').'-'.$this->runId.'.json';
        file_put_contents($path, Json::encode($report, true).PHP_EOL, LOCK_EX);

        return ['path' => $path, 'report' => $report];
    }
}
