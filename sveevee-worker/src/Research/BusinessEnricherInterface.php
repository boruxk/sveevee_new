<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use Sveevee\Worker\Domain\BusinessCandidate;

interface BusinessEnricherInterface
{
    public function name(): string;

    public function supports(BusinessCandidate $candidate): bool;

    public function enrich(BusinessCandidate $candidate): BusinessCandidate;

    public function refreshAfterDays(): int;
}
