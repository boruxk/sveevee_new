<?php

namespace App\Services\Billing;

use RuntimeException;

/** A deliberately redacted exception: provider bodies and credentials never leave the client. */
class CardcomException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $ambiguous = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $providerCode = null,
    ) {
        parent::__construct('Cardcom request could not be completed ('.$reason.').');
    }
}
