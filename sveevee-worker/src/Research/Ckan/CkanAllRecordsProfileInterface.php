<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Ckan;

interface CkanAllRecordsProfileInterface extends CkanDatasetProfileInterface
{
    /** No city, category, active-state or expiry restrictions. */
    public function fullSearchParameters(): array;

    /** Missing columns indicate schema drift; null values are individual invalid records. */
    public function assertRecordSchema(array $record): void;

    /** Missing mandatory data remains visible for durable pipeline rejection. */
    public function mapAll(array $record, array $dataset, string $sourceUrl, string $checkedAt): array;
}
