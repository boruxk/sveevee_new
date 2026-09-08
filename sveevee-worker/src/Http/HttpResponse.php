<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return is_array($values) ? end($values) ?: null : null;
    }
}

