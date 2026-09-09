<?php

declare(strict_types=1);

namespace Sveevee\Worker\Storage;

use RuntimeException;

final class IdentityConflictException extends RuntimeException
{
    public function __construct(
        public readonly array $businessIds,
        string $message = 'Candidate identity signals belong to different local businesses.',
    ) {
        parent::__construct($message);
    }
}
