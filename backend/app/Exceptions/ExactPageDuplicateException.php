<?php

namespace App\Exceptions;

use RuntimeException;

class ExactPageDuplicateException extends RuntimeException
{
    public function __construct(public readonly array $matches)
    {
        parent::__construct('An exact page duplicate already exists.');
    }
}
