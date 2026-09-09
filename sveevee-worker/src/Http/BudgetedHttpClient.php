<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

final class BudgetedHttpClient implements HttpClientInterface
{
    private int $requests = 0;

    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly int $limit,
        private readonly ?\Closure $onRequest = null,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Source request budget must be positive.');
        }
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): HttpResponse {
        if ($this->exhausted()) {
            throw new SourceRequestBudgetExceeded($this->limit);
        }
        $this->requests++;
        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }

        return $this->inner->request($method, $url, $headers, $body, $options);
    }

    public function exhausted(): bool
    {
        return $this->requests >= $this->limit;
    }

    public function requestCount(): int
    {
        return $this->requests;
    }
}
