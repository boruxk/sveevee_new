<?php

declare(strict_types=1);

namespace Sveevee\Worker\Api;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reason,
        public readonly mixed $errors = null,
        public readonly mixed $data = null,
        public readonly ?string $requestId = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}

