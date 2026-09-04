<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EnsureOwnBroadcastChannel
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedChannel = 'private-users.'.(int) $request->user()->id;

        if (! hash_equals($expectedChannel, (string) $request->input('channel_name'))) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
