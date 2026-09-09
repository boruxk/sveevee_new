<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Ckan;

use Sveevee\Worker\Config\ResearchTarget;

/** A large dataset that must be filtered remotely before it is paginated. */
interface CkanScopedDatasetProfileInterface extends CkanDatasetProfileInterface
{
    public function searchParameters(array $dataset, ResearchTarget $target): array;

    public function recordFilters(string $recordId): array;

    /** Keep only eligible records, across all supported categories, in the city cache. */
    public function keepRecord(array $record, array $dataset, ResearchTarget $target): bool;
}
