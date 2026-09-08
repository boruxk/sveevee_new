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

    public function source(string $name, int $amount = 1): void
    {
        $this->sources[$name] = ($this->sources[$name] ?? 0) + $amount;
    }

    public function target(string $key, string $city, string $categoryKey, int $found): void
    {
        $this->targets[] = [
            'key' => $key,
            'city' => $city,
            'category_key' => $categoryKey,
            'found' => $found,
        ];
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
            'targets' => $this->targets,
            'used_sources' => array_keys($this->sources),
            'source_counts' => $this->sources,
            'errors' => $this->errors,
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
