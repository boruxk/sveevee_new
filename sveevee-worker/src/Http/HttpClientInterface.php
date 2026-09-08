<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

interface HttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): HttpResponse;
}

