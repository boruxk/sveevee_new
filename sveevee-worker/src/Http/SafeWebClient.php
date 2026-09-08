<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

use RuntimeException;

final class SafeWebClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly UrlGuard $guard,
        private readonly string $userAgent,
    ) {}

    public function get(string $url, int $timeout, int $maximumBytes, int $redirects = 3): HttpResponse
    {
        $current = $url;
        for ($hop = 0; $hop <= $redirects; $hop++) {
            $ip = $this->guard->publicIp($current);
            $response = $this->http->request('GET', $current, [
                'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.2',
            ], null, [
                'timeout' => $timeout,
                'max_bytes' => $maximumBytes,
                'user_agent' => $this->userAgent,
                'resolve_ip' => $ip,
            ]);
            if (! in_array($response->status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            $location = $response->header('location');
            if ($location === null) {
                return $response;
            }
            $current = $this->guard->resolve($current, $location);
        }

        throw new RuntimeException('Website redirected too many times.');
    }
}
