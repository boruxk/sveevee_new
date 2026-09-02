<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePlatformIsAvailable;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\VerifyRecaptcha;
use App\Services\ApiResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'admin' => EnsureUserIsAdmin::class,
            'platform.available' => EnsurePlatformIsAvailable::class,
            'recaptcha' => VerifyRecaptcha::class,
            'email.verified' => EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return ApiResponseService::validationError(
                    message: 'Validation failed.',
                    errors: $exception->errors(),
                    status: $exception->status
                );
            }

            if ($exception instanceof AuthenticationException) {
                return ApiResponseService::error('Unauthenticated.', status: 401);
            }

            if ($exception instanceof AuthorizationException) {
                return ApiResponseService::error('This action is unauthorized.', status: 403);
            }

            if ($exception instanceof AccessDeniedHttpException) {
                return ApiResponseService::error('This action is unauthorized.', status: 403);
            }

            if ($exception instanceof NotFoundHttpException) {
                return ApiResponseService::error('Resource not found.', status: 404);
            }

            if ($exception instanceof TooManyRequestsHttpException) {
                $response = ApiResponseService::error('Too many requests.', status: 429);

                foreach ($exception->getHeaders() as $name => $value) {
                    $response->headers->set($name, $value);
                }

                return $response;
            }

            return ApiResponseService::error(
                config('app.debug') ? $exception->getMessage() : 'Server error.',
                status: 500
            );
        });
    })->create();
