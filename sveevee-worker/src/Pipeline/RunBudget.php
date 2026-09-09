<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Config\ResearchTarget;

/** Counts completed writes (or planned writes during a dry run), never candidates. */
final class RunBudget
{
    private array $counts = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $targetLimit,
        private readonly int $perCombination,
    ) {}

    public function remaining(ResearchTarget $target): int
    {
        if (! isset($this->counts[$target->city.'|'.$target->categoryKey]) && count($this->counts) >= $this->targetLimit) {
            return 0;
        }

        return max(0, min(
            $this->limit - array_sum($this->counts),
            $this->perCombination - $this->count($target),
        ));
    }

    public function count(ResearchTarget $target): int
    {
        return $this->counts[$target->city.'|'.$target->categoryKey] ?? 0;
    }

    public function record(ResearchTarget $target, int $count = 1): void
    {
        if ($count > 0) {
            $this->counts[$target->city.'|'.$target->categoryKey] = $this->count($target) + $count;
        }
    }

    /** A persisted request must be replayed intact, so reserve its worst case. */
    public function fits(array $targets): bool
    {
        $reservation = clone $this;
        foreach ($targets as $target) {
            if ($reservation->remaining($target) < 1) {
                return false;
            }
            $reservation->record($target);
        }

        return true;
    }
}
