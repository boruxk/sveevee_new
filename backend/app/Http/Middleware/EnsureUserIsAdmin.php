<?php

namespace App\Http\Middleware;

use App\Services\ApiResponseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin')) {
            return ApiResponseService::error(
                'You do not have admin access to this area.',
                ['roles' => ['admin']],
                403
            );
        }

        return $next($request);
    }
}
