<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\SystemSettingsService;
use App\Support\CatalogTopics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSettingsController extends Controller
{
    public function index(SystemSettingsService $settings)
    {
        return ApiResponseService::success([
            'settings' => $settings->all(),
            'catalog_topics' => CatalogTopics::all()->values()->all(),
        ]);
    }

    public function update(Request $request, string $section, SystemSettingsService $settings)
    {
        if (! in_array($section, SystemSettingsService::SECTIONS, true)) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $data = $request->validate($this->rules($section));

        return ApiResponseService::success([
            'section' => $section,
            'settings' => $settings->updateSection($section, $data, $request->user()?->id),
        ], 'Settings saved.');
    }

    private function rules(string $section): array
    {
        return match ($section) {
            'ads' => [
                'visibility_days' => ['required', 'integer', 'min:1', 'max:365'],
                'private_active_limit' => ['required', 'integer', 'min:1', 'max:1000'],
                'page_active_limit' => ['required', 'integer', 'min:1', 'max:5000'],
                'purge_after_expiry_days' => ['required', 'integer', 'min:0', 'max:3650'],
            ],
            'labels' => [
                'new_days' => ['required', 'integer', 'min:1', 'max:365'],
                'popular_views' => ['required', 'integer', 'min:1', 'max:10000000'],
                'popular_contacts' => ['required', 'integer', 'min:1', 'max:1000000'],
                'highly_rated_average' => ['required', 'numeric', 'min:1', 'max:5'],
                'highly_rated_min_ratings' => ['required', 'integer', 'min:1', 'max:100000'],
            ],
            'chat' => [
                'new_recipients_per_day' => ['required', 'integer', 'min:1', 'max:10000'],
                'messages_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
                'guest_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            ],
            'moderation' => [
                'products_per_business_page' => ['required', 'integer', 'min:1', 'max:100000'],
                'future_events_per_community_page' => ['required', 'integer', 'min:1', 'max:10000'],
            ],
            'platform' => [
                'maintenance_enabled' => ['required', 'boolean'],
                'maintenance_messages' => ['required', 'array:he,en,ru,fr'],
                'maintenance_messages.he' => ['required', 'string', 'max:500'],
                'maintenance_messages.en' => ['required', 'string', 'max:500'],
                'maintenance_messages.ru' => ['required', 'string', 'max:500'],
                'maintenance_messages.fr' => ['required', 'string', 'max:500'],
                'popular_topic_keys' => ['present', 'array', 'max:12'],
                'popular_topic_keys.*' => [
                    'required',
                    'string',
                    'distinct',
                    Rule::in(CatalogTopics::all()->pluck('key')->all()),
                ],
            ],
        };
    }
}
