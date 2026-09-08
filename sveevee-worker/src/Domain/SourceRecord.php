<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

final readonly class SourceRecord
{
    public function __construct(
        public string $adapter,
        public string $name,
        public ?string $url,
        public string $checkedAt,
        public array $raw,
    ) {}
}

