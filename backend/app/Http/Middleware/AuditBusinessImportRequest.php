<?php

namespace App\Http\Middleware;

use App\Models\BusinessImportApiLog;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditBusinessImportRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = (string) Str::uuid();
        $statusCode = 500;

        try {
            $response = $next($request);
            $statusCode = $response->getStatusCode();
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (Throwable $exception) {
            $statusCode = $this->statusCode($exception);

            throw $exception;
        } finally {
            $this->writeLog($request, $requestId, $statusCode, $startedAt);
        }
    }

    private function writeLog(Request $request, string $requestId, int $statusCode, int $startedAt): void
    {
        $body = $request->getContent();
        $businesses = $request->input('businesses');
        $itemCount = is_array($businesses)
            ? count($businesses)
            : ($request->isMethod('GET') ? null : 1);

        try {
            BusinessImportApiLog::query()->create([
                'request_id' => $requestId,
                'oauth_client_id' => $request->attributes->get('oauth_client_id'),
                'oauth_access_token_id' => $request->attributes->get('oauth_access_token_id') ?: null,
                'method' => $request->getMethod(),
                'route_name' => $request->route()?->getName(),
                'path' => mb_substr($request->path(), 0, 2048),
                'status_code' => $statusCode,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024) ?: null,
                'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'payload_bytes' => strlen($body),
                'item_count' => $itemCount,
                'payload_hash' => $body !== '' ? hash('sha256', $body) : null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Business import API request could not be audited.', [
                'request_id' => $requestId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function statusCode(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
    }
}
