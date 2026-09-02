<?php

namespace App\Http\Middleware;

use App\Services\ApiResponseService;
use App\Services\EmailVerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->verification->canUseEmailFeatures($user)) {
            return ApiResponseService::error(
                'A verified email address is required.',
                status: 409,
                data: [
                    'email_verification' => $user
                        ? $this->verification->payload($user)
                        : null,
                ]
            );
        }

        return $next($request);
    }
}
