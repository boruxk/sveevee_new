<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

final readonly class RobotsDecision
{
    public function __construct(
        public bool $allowed,
        public ?float $crawlDelay,
        public string $reason,
    ) {}
}

