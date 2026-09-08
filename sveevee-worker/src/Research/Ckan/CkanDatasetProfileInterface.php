<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Ckan;

use Sveevee\Worker\Config\ResearchTarget;

interface CkanDatasetProfileInterface
{
    public function name(): string;

    public function supports(array $dataset, ResearchTarget $target): bool;

    public function recordId(array $record): ?string;

    public function map(
        array $record,
        array $dataset,
        ResearchTarget $target,
        string $sourceUrl,
        string $checkedAt,
    ): ?array;
}
