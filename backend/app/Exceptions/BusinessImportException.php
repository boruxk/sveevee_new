<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessImportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 409,
        public readonly string $reason = 'conflict',
    ) {
        parent::__construct($message);
    }
}
