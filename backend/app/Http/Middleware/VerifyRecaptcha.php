<?php

namespace App\Http\Middleware;

use App\Services\ApiResponseService;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyRecaptcha
{
    public function handle(Request $request, Closure $next, string $mode = 'mutating'): Response
    {
        $safeRequestNeedsRecaptcha = $mode === 'always' || $request->is('api/v1/search');

        if (
            ($request->isMethodSafe() && ! $safeRequestNeedsRecaptcha)
            || ! config('recaptcha.enabled')
            || ! config('recaptcha.secret_key')
        ) {
            return $next($request);
        }

        $token = (string) $request->header('X-Recaptcha-Token', '');
        $action = (string) $request->header('X-Recaptcha-Action', '');

        if ($token === '' || $action === '') {
            Log::notice('reCAPTCHA request is missing verification data.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'has_token' => $token !== '',
                'has_action' => $action !== '',
            ]);

            return ApiResponseService::error(
                message: 'reCAPTCHA verification failed.',
                errors: ['recaptcha' => ['Missing reCAPTCHA token.']],
                status: 422
            );
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(3)
                ->timeout(8)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('recaptcha.secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (ConnectionException) {
            Log::warning('reCAPTCHA provider could not be reached.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'action' => $action,
            ]);

            return ApiResponseService::error(
                message: 'reCAPTCHA verification failed.',
                errors: ['recaptcha' => ['Could not verify reCAPTCHA token.']],
                status: 422
            );
        }

        if (! $response->ok()) {
            Log::warning('reCAPTCHA provider returned an unsuccessful response.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'action' => $action,
                'status' => $response->status(),
            ]);

            return ApiResponseService::error(
                message: 'reCAPTCHA verification failed.',
                errors: ['recaptcha' => ['Could not verify reCAPTCHA token.']],
                status: 422
            );
        }

        $payload = $response->json();
        $score = (float) ($payload['score'] ?? 0);
        $minScore = (float) config('recaptcha.min_score', 0.5);

        if (
            ! ($payload['success'] ?? false)
            || ($payload['action'] ?? '') !== $action
            || $score < $minScore
        ) {
            Log::notice('reCAPTCHA verification was rejected.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'expected_action' => $action,
                'provider_action' => $payload['action'] ?? null,
                'success' => (bool) ($payload['success'] ?? false),
                'score' => $payload['score'] ?? null,
                'min_score' => $minScore,
                'hostname' => $payload['hostname'] ?? null,
                'error_codes' => array_values((array) ($payload['error-codes'] ?? [])),
            ]);

            return ApiResponseService::error(
                message: 'reCAPTCHA verification failed.',
                errors: ['recaptcha' => ['Please try again.']],
                status: 422
            );
        }

        return $next($request);
    }
}
