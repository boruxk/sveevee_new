<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

final readonly class BusinessCandidate
{
    /** @param SourceRecord[] $sources */
    public function __construct(
        public array $data,
        public array $sources,
        public array $warnings = [],
    ) {}

    public function withDataAndSource(array $data, SourceRecord $source, array $warnings = []): self
    {
        return new self($data, [...$this->sources, $source], [...$this->warnings, ...$warnings]);
    }
}

