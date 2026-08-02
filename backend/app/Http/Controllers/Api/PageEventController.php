<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Models\Page;
use App\Models\PageEvent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class PageEventController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function store(Request $request, Page $page)
    {
        if ($page->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if ($page->type !== Page::TYPE_COMMUNITY) {
            return ApiResponseService::error('Events are available only for community pages.', status: 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:3000'],
            'image' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'address' => ['required', 'string', 'max:255'],
        ]);

        $image = $request->file('image');

        $event = PageEvent::query()->create([
            'page_id' => $page->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'image_path' => $image->store('events', 'public'),
            'image_original_name' => $this->originalUploadName($request, 'image', $image),
            'event_date' => $data['date'],
            'event_time' => $data['time'],
            'address' => $data['address'],
        ]);

        return ApiResponseService::success($this->payloads->event($event), 'Event created.', 201);
    }
}
