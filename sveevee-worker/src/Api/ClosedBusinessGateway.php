<?php

declare(strict_types=1);

namespace Sveevee\Worker\Api;

interface ClosedBusinessGateway
{
    public function removeClosedBusinesses(array $request): array;
}
