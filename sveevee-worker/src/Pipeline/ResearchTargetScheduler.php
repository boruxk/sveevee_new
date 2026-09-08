<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Storage\WorkerRepository;

final class ResearchTargetScheduler
{
    public function __construct(private readonly WorkerRepository $repository) {}

    /**
     * @param ResearchTarget[] $targets
     * @return ResearchTarget[]
     */
    public function next(array $targets, int $limit): array
    {
        $targets = $this->uniqueTargets($targets);
        $progress = $this->repository->researchTargetProgress();
        $positions = array_flip(array_keys($targets));

        uasort($targets, static function (ResearchTarget $left, ResearchTarget $right) use ($progress, $positions): int {
            $leftCompleted = $progress[$left->key()]['last_completed_at'] ?? null;
            $rightCompleted = $progress[$right->key()]['last_completed_at'] ?? null;

            if ($leftCompleted === null && $rightCompleted !== null) {
                return -1;
            }
            if ($leftCompleted !== null && $rightCompleted === null) {
                return 1;
            }
            if ($leftCompleted !== $rightCompleted) {
                return strcmp((string) $leftCompleted, (string) $rightCompleted);
            }

            return $positions[$left->key()] <=> $positions[$right->key()];
        });

        return array_slice(array_values($targets), 0, max(1, $limit));
    }

    /**
     * @param ResearchTarget[] $targets
     */
    public function summary(array $targets, int $limit): array
    {
        $targets = $this->uniqueTargets($targets);
        $progress = $this->repository->researchTargetProgress();
        $next = $this->next(array_values($targets), $limit);
        $visited = count(array_intersect(array_keys($targets), array_keys($progress)));

        return [
            'configured_combinations' => count($targets),
            'combinations_per_run' => min(max(1, $limit), count($targets)),
            'visited_combinations' => $visited,
            'unvisited_combinations' => count($targets) - $visited,
            'next_combinations' => array_map($this->describe(...), $next),
        ];
    }

    /**
     * @param ResearchTarget[] $targets
     * @return array<string, ResearchTarget>
     */
    private function uniqueTargets(array $targets): array
    {
        $unique = [];
        foreach ($targets as $target) {
            $unique[$target->key()] = $target;
        }

        return $unique;
    }

    private function describe(ResearchTarget $target): array
    {
        return [
            'key' => $target->key(),
            'city' => $target->city,
            'category_key' => $target->categoryKey,
        ];
    }
}
