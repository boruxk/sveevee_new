<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use Sveevee\Worker\Config\ResearchTarget;

interface SourceAdapterInterface
{
    public function name(): string;

    /** @return iterable<array> */
    public function research(ResearchTarget $target, int $limit): iterable;

    public function refreshAfterDays(): int;
}

