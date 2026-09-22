<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\ApiResponseService;
use App\Services\BusinessProEntitlementService;
use Illuminate\Http\Request;

class BusinessProController extends Controller
{
    public function index(Request $request, BusinessProEntitlementService $entitlements)
    {
        return ApiResponseService::success($entitlements->overview($request->user()));
    }

    public function show(Request $request, Page $page, BusinessProEntitlementService $entitlements)
    {
        return ApiResponseService::success($entitlements->overview($request->user(), $page));
    }
}
