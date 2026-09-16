<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessImportException;
use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\ClosedBusinessService;
use Illuminate\Http\Request;

final class ClosedBusinessController extends Controller
{
    public function store(Request $request, ClosedBusinessService $closures)
    {
        try {
            $result = $closures->process((string) $request->attributes->get('oauth_client_id'), $request->all());
        } catch (BusinessImportException $error) {
            return ApiResponseService::error($error->getMessage(), [$error->reason => [$error->getMessage()]], $error->status);
        }

        return ApiResponseService::success($result, $result['dry_run'] ? 'Closed businesses preview completed.' : 'Closed businesses processed.');
    }
}
