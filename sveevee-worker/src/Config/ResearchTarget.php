<?php

declare(strict_types=1);

namespace Sveevee\Worker\Config;

final readonly class ResearchTarget
{
    public function __construct(
        public string $city,
        public string $categoryKey,
        public ?string $neighborhood = null,
    ) {}

    public function key(): string
    {
        return implode('|', [$this->city, $this->neighborhood ?? '', $this->categoryKey]);
    }
}

