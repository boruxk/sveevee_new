<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

use RuntimeException;

final class IncompleteCandidateException extends RuntimeException
{
    public function __construct(public readonly array $missingFields)
    {
        parent::__construct('Business is missing required fields: '.implode(', ', $missingFields));
    }
}

