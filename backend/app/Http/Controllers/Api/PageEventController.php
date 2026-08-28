<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageEvent;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Services\SystemSettingsService;
use App\Support\CatalogTopics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PageEventController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(
        private readonly PayloadService $payloads,
        private readonly SystemSettingsService $settings,
    ) {}

    public function store(Request $request, Page $page)
    {
        if ($error = $this->guardCommunityPage($request, $page)) {
            return $error;
        }

        $this->normalizeCategoryKey($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent],
            'description' => ['required', 'string', 'max:5000', new CleanContent],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope(CatalogTopics::SCOPE_EVENTS))],
            'image' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'address' => ['required', 'string', 'max:255'],
        ]);

        if (Carbon::parse($data['date'])->startOfDay()->gte(today())) {
            $limit = $this->settings->integer('moderation.future_events_per_community_page', 50);
            $futureEvents = $page->events()->whereDate('event_date', '>=', today())->count();

            if ($futureEvents >= $limit) {
                return ApiResponseService::error(
                    message: 'The future event limit for this community page has been reached.',
                    status: 422,
                    data: ['reason' => 'event_limit', 'limit' => $limit]
                );
            }
        }

        $image = $request->file('image');

        $event = PageEvent::query()->create([
            'page_id' => $page->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'category_key' => $data['category_key'],
            'image_path' => $this->storePublicWebp($image, 'events', 'image'),
            'image_original_name' => $this->originalUploadName($request, 'image', $image),
            'event_date' => $data['date'],
            'event_time' => $data['time'],
            'event_end_time' => $data['end_time'] ?? null,
            'address' => $data['address'],
        ]);

        return ApiResponseService::success($this->payloads->event($event), 'Event created.', 201);
    }

    public function update(Request $request, PageEvent $event)
    {
        $event->loadMissing('page');

        if ($error = $this->guardCommunityPage($request, $event->page)) {
            return $error;
        }

        $this->normalizeCategoryKey($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent],
            'description' => ['required', 'string', 'max:5000', new CleanContent],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope(CatalogTopics::SCOPE_EVENTS))],
            'image' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'image_remove' => ['nullable', 'boolean'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'address' => ['required', 'string', 'max:255'],
        ]);

        $event->fill([
            'name' => $data['name'],
            'description' => $data['description'],
            'category_key' => $data['category_key'],
            'event_date' => $data['date'],
            'event_time' => $data['time'],
            'event_end_time' => $data['end_time'] ?? null,
            'address' => $data['address'],
        ]);

        if ($request->boolean('image_remove') || $request->hasFile('image')) {
            $this->deletePublicUpload($event->image_path);
            $event->image_path = '';
            $event->image_original_name = null;
        }

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $event->image_path = $this->storePublicWebp($image, 'events', 'image');
            $event->image_original_name = $this->originalUploadName($request, 'image', $image);
        }

        $event->save();

        return ApiResponseService::success($this->payloads->event($event->fresh()), 'Event saved.');
    }

    public function destroy(Request $request, PageEvent $event)
    {
        $event->loadMissing('page');

        if ($error = $this->guardCommunityPage($request, $event->page)) {
            return $error;
        }

        $this->deletePublicUpload($event->image_path);
        $event->delete();

        return ApiResponseService::success(null, 'Event deleted.');
    }

    private function guardCommunityPage(Request $request, Page $page)
    {
        if ($page->is_unclaimed || $page->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if ($page->type !== Page::TYPE_COMMUNITY) {
            return ApiResponseService::error('Events are available only for community pages.', status: 422);
        }

        return null;
    }

    private function normalizeCategoryKey(Request $request): void
    {
        $key = trim((string) $request->input('category_key', ''));

        if ($key === '') {
            return;
        }

        $request->merge([
            'category_key' => CatalogTopics::canonicalKeyForScope($key, CatalogTopics::SCOPE_EVENTS) ?? $key,
        ]);
    }
}
