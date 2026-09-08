<?php

declare(strict_types=1);

namespace Sveevee\Worker\Api;

use RuntimeException;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\TransportException;
use Sveevee\Worker\Support\Json;

final class OAuthTokenProvider
{
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly int $timeout,
        private readonly string $userAgent,
    ) {}

    public function token(): string
    {
        if ($this->accessToken !== null && time() < $this->expiresAt - 60) {
            return $this->accessToken;
        }

        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->http->request('POST', $this->tokenUrl, [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ], http_build_query([
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'business:read business:write',
                ]), [
                    'timeout' => $this->timeout,
                    'max_bytes' => 1_048_576,
                    'user_agent' => $this->userAgent,
                ]);
                $decoded = Json::decode($response->body);
                if ($response->status < 200 || $response->status >= 300 || ! is_array($decoded)) {
                    $message = is_array($decoded)
                        ? (string) ($decoded['error_description'] ?? $decoded['message'] ?? 'OAuth token request failed.')
                        : 'OAuth token request failed.';
                    if (($response->status === 429 || $response->status >= 500) && $attempt < 2) {
                        $retryAfter = (int) ($response->header('retry-after') ?? 0);
                        sleep(min(30, max($retryAfter, 2 ** $attempt)));
                        continue;
                    }
                    throw new RuntimeException("{$message} (HTTP {$response->status})");
                }
                $token = $decoded['access_token'] ?? null;
                if (! is_string($token) || $token === '') {
                    throw new RuntimeException('OAuth response did not contain an access token.');
                }
                $this->accessToken = $token;
                $this->expiresAt = time() + max(120, (int) ($decoded['expires_in'] ?? 3600));

                return $token;
            } catch (TransportException $exception) {
                $lastError = $exception;
                if ($attempt < 2) {
                    sleep(2 ** $attempt);
                }
            }
        }

        throw new RuntimeException('OAuth token endpoint is unreachable.', previous: $lastError);
    }

    public function invalidate(): void
    {
        $this->accessToken = null;
        $this->expiresAt = 0;
    }
}
