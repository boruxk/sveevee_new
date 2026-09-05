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

    public function index(Request $request)
    {
        $today = today()->toDateString();
        $events = PageEvent::query()
            ->with(['user.profile', 'user.pages'])
            ->where('user_id', $request->user()->id)
            ->whereNull('page_id')
            ->orderByRaw('CASE WHEN event_date >= ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw('CASE WHEN event_date >= ? THEN event_date END ASC', [$today])
            ->orderByRaw('CASE WHEN event_date < ? THEN event_date END DESC', [$today])
            ->orderBy('event_time')
            ->get()
            ->map(fn (PageEvent $event): array => $this->payloads->event($event))
            ->values();

        return ApiResponseService::success($events);
    }

    public function store(Request $request, Page $page)
    {
        if ($error = $this->guardCommunityPage($request, $page)) {
            return $error;
        }

        $data = $this->validatedData($request, imageRequired: true);

        if ($error = $this->futureEventLimitResponse($data['date'], $page->events())) {
            return $error;
        }

        $event = $this->createEvent($request, $data, pageId: $page->id);

        return ApiResponseService::success($this->payloads->event($event), 'Event created.', 201);
    }

    public function storePersonal(Request $request)
    {
        $data = $this->validatedData($request, imageRequired: true);

        if ($error = $this->futureEventLimitResponse($data['date'], $request->user()->events())) {
            return $error;
        }

        $event = $this->createEvent($request, $data, userId: $request->user()->id)
            ->load(['user.profile', 'user.pages']);

        return ApiResponseService::success($this->payloads->event($event), 'Event created.', 201);
    }

    public function update(Request $request, PageEvent $event)
    {
        $event->loadMissing(['page', 'user']);

        if ($error = $this->guardEventOwner($request, $event)) {
            return $error;
        }

        $data = $this->validatedData($request, imageRequired: false);

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

        $freshEvent = $event->fresh();
        if ($freshEvent->user_id) {
            $freshEvent->load(['user.profile', 'user.pages']);
        }

        return ApiResponseService::success($this->payloads->event($freshEvent), 'Event saved.');
    }

    public function destroy(Request $request, PageEvent $event)
    {
        $event->loadMissing(['page', 'user']);

        if ($error = $this->guardEventOwner($request, $event)) {
            return $error;
        }

        $this->deletePublicUpload($event->image_path);
        $event->delete();

        return ApiResponseService::success(null, 'Event deleted.');
    }

    private function validatedData(Request $request, bool $imageRequired): array
    {
        $this->normalizeCategoryKey($request);

        return $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent],
            'description' => ['required', 'string', 'max:5000', new CleanContent],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope(CatalogTopics::SCOPE_EVENTS))],
            'image' => [$imageRequired ? 'required' : 'nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'image_remove' => ['nullable', 'boolean'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'address' => ['required', 'string', 'max:255'],
        ]);
    }

    private function createEvent(Request $request, array $data, ?int $pageId = null, ?int $userId = null): PageEvent
    {
        $image = $request->file('image');

        return PageEvent::query()->create([
            'page_id' => $pageId,
            'user_id' => $userId,
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
    }

    private function futureEventLimitResponse(string $date, $events)
    {
        if (Carbon::parse($date)->startOfDay()->lt(today())) {
            return null;
        }

        $limit = $this->settings->integer('moderation.future_events_per_community_page', 50);
        $futureEvents = $events->whereDate('event_date', '>=', today())->count();

        if ($futureEvents < $limit) {
            return null;
        }

        return ApiResponseService::error(
            message: 'The future event limit has been reached.',
            status: 422,
            data: ['reason' => 'event_limit', 'limit' => $limit]
        );
    }

    private function guardEventOwner(Request $request, PageEvent $event)
    {
        if ($event->page_id) {
            return $event->page
                ? $this->guardCommunityPage($request, $event->page)
                : ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if (! $event->user_id || $event->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        return null;
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
