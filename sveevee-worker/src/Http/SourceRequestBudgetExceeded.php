<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

final class SourceRequestBudgetExceeded extends \RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("Source HTTP request budget of {$limit} was reached; remaining research is deferred.");
    }
}
