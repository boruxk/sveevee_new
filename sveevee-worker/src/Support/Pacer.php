<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

final class Pacer
{
    private float $lastRequestAt = 0.0;

    public function __construct(private readonly int $minimumIntervalMs) {}

    public function wait(): void
    {
        if ($this->lastRequestAt > 0 && $this->minimumIntervalMs > 0) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            $remainingMs = $this->minimumIntervalMs - $elapsedMs;
            if ($remainingMs > 0) {
                usleep((int) ceil($remainingMs * 1000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }
}

