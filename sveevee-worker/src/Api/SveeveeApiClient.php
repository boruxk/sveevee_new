<?php

declare(strict_types=1);

namespace Sveevee\Worker\Api;

use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Http\HttpResponse;
use Sveevee\Worker\Http\TransportException;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Pacer;

final class SveeveeApiClient implements SveeveeGateway
{
    private readonly Pacer $pacer;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly OAuthTokenProvider $tokens,
        private readonly string $baseUrl,
        private readonly int $timeout,
        int $requestIntervalMs,
        private readonly int $maxRetries,
        private readonly string $userAgent,
    ) {
        $this->pacer = new Pacer(max(0, $requestIntervalMs));
    }

    public function checkDuplicate(array $business): array
    {
        $payload = array_intersect_key($business, array_flip([
            'id', 'type', 'name', 'contact_email', 'phone', 'website', 'category_key', 'address', 'source', 'dry_run',
        ]));

        return $this->request('POST', '/businesses/duplicates', $payload);
    }

    public function searchBusinesses(array $filters): array
    {
        return $this->request('GET', '/businesses?'.http_build_query($filters));
    }

    public function importBatch(array $request): array
    {
        $businesses = $request['businesses'] ?? null;
        if (! is_array($businesses) || $businesses === [] || count($businesses) > 100) {
            throw new \InvalidArgumentException('Worker batches must contain between 1 and 100 businesses.');
        }

        return $this->request('POST', '/businesses/batch', $request);
    }

    public function reportRun(array $report): array
    {
        return $this->request('POST', '/worker-runs', $report);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $lastTransport = null;
        $retriedUnauthorized = false;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $this->pacer->wait();
            try {
                $headers = [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->tokens->token(),
                ];
                $body = null;
                if ($payload !== null && strtoupper($method) !== 'GET') {
                    $headers['Content-Type'] = 'application/json';
                    $body = Json::encode($payload);
                }
                $response = $this->http->request(
                    $method,
                    rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'),
                    $headers,
                    $body,
                    [
                        'timeout' => $this->timeout,
                        'max_bytes' => 10_485_760,
                        'user_agent' => $this->userAgent,
                    ]
                );
            } catch (TransportException $exception) {
                $lastTransport = $exception;
                if ($attempt < $this->maxRetries) {
                    sleep(min(30, 2 ** $attempt));

                    continue;
                }
                throw new ApiException(
                    'Sveevee API transport failed: '.$exception->getMessage(),
                    0,
                    'transport',
                    retryable: true,
                );
            }

            if ($response->status === 401 && ! $retriedUnauthorized) {
                $retriedUnauthorized = true;
                $this->tokens->invalidate();

                continue;
            }
            if (($response->status === 429 || $response->status >= 500) && $attempt < $this->maxRetries) {
                $retryAfter = (int) ($response->header('retry-after') ?? 0);
                sleep(min(60, max($retryAfter, 2 ** $attempt)));

                continue;
            }

            return $this->decode($response);
        }

        throw new ApiException(
            'Sveevee API request failed after retries.',
            0,
            'transport',
            retryable: true,
        );
    }

    private function decode(HttpResponse $response): array
    {
        try {
            $decoded = Json::decode($response->body);
        } catch (\JsonException) {
            throw new ApiException(
                "Sveevee API returned invalid JSON (HTTP {$response->status}).",
                $response->status,
                'invalid_response',
                requestId: $response->header('x-request-id'),
                retryable: $response->status >= 500,
            );
        }
        if (! is_array($decoded)) {
            throw new ApiException('Sveevee API returned an invalid response.', $response->status, 'invalid_response');
        }
        if ($response->status < 200 || $response->status >= 300 || ($decoded['success'] ?? false) !== true) {
            $errors = $decoded['errors'] ?? null;
            $reason = $this->reason($response->status, $errors);
            $message = (string) ($decoded['message'] ?? "Sveevee API request failed with HTTP {$response->status}.");
            $processingConflict = $response->status === 409
                && (str_contains(strtolower($message), 'not complete')
                    || str_contains(strtolower($message), 'being processed'));
            throw new ApiException(
                $message,
                $response->status,
                $reason,
                $errors,
                $decoded['data'] ?? null,
                $response->header('x-request-id'),
                $response->status === 429 || $response->status >= 500 || $processingConflict,
            );
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    private function reason(int $status, mixed $errors): string
    {
        if (is_array($errors) && $errors !== []) {
            return (string) array_key_first($errors);
        }

        return match ($status) {
            409 => 'conflict',
            422 => 'validation',
            404 => 'not_found',
            429 => 'rate_limited',
            default => 'api_error',
        };
    }
}
