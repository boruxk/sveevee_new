<?php

namespace App\Services\Billing;

use RuntimeException;

class BusinessProBillingException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 409)
    {
        parent::__construct($reason);
    }
}
