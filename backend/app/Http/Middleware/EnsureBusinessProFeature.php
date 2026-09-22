<?php

namespace App\Http\Middleware;

use App\Models\Page;
use App\Services\BusinessProEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessProFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $page = $request->route('page');
        abort_unless($request->user() && $page instanceof Page, 404);
        app(BusinessProEntitlementService::class)->assertFeature($request->user(), $page, $feature);

        return $next($request);
    }
}
