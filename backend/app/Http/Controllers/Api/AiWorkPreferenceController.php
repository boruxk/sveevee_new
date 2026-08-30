<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiWorkPreference;
use App\Services\AiWorkPageService;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class AiWorkPreferenceController extends Controller
{
    public function __construct(private readonly AiWorkPageService $pages) {}

    public function show(Request $request)
    {
        $preference = AiWorkPreference::query()->where('user_id', $request->user()->id)->first();

        return ApiResponseService::success([
            'page_defaults' => [...$this->pages->defaultPreferences(), ...($preference?->page_defaults ?? [])],
        ]);
    }

    public function update(Request $request)
    {
        $defaults = $this->pages->validatePreferences($request->input('page_defaults', []));
        $preference = AiWorkPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['page_defaults' => $defaults]
        );

        return ApiResponseService::success(['page_defaults' => $preference->page_defaults], 'Preferences saved.');
    }
}
