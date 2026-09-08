<?php

declare(strict_types=1);

namespace Sveevee\Worker\Api;

interface SveeveeGateway
{
    public function checkDuplicate(array $business): array;

    public function searchBusinesses(array $filters): array;

    public function importBatch(array $request): array;

    public function reportRun(array $report): array;
}
