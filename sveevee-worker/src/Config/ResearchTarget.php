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
        $parts = [$this->city];
        if ($this->neighborhood !== null) {
            $parts[] = $this->neighborhood;
        }
        $parts[] = $this->categoryKey;

        return implode('|', $parts);
    }
}
