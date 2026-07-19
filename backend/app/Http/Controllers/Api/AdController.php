<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Page;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $query = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($inner) => $inner->whereNull('banned_at'))
            ->latest();

        if ($request->query('scope') === 'mine') {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('page_id')) {
            $query->where('page_id', $request->integer('page_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        return ApiResponseService::success(
            $query->limit((int) $request->query('limit', 50))->get()->map(fn (Ad $ad) => $this->payloads->ad($ad))->values()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:5000'],
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'image' => ['nullable', 'image', 'max:6144'],
        ]);

        $page = null;
        if (! empty($data['page_id'])) {
            $page = Page::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($data['page_id']);
        }

        $ad = Ad::query()->create([
            'user_id' => $request->user()->id,
            'page_id' => $page?->id,
            'type' => $this->typeForPage($page),
            'title' => $data['title'],
            'text' => $data['text'],
            'image_path' => $request->hasFile('image') ? $request->file('image')->store('ads', 'public') : null,
            'status' => 'active',
        ]);

        return ApiResponseService::success($this->payloads->ad($ad), 'Ad created.', 201);
    }

    public function update(Request $request, Ad $ad)
    {
        if (! $this->canManage($request, $ad)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(['active', 'paused'])],
            'image' => ['nullable', 'image', 'max:6144'],
        ]);

        $ad->fill([
            'title' => $data['title'],
            'text' => $data['text'],
            'status' => $data['status'] ?? $ad->status,
        ]);

        if ($request->hasFile('image')) {
            $ad->image_path = $request->file('image')->store('ads', 'public');
        }

        $ad->save();

        return ApiResponseService::success($this->payloads->ad($ad->fresh()), 'Ad saved.');
    }

    public function destroy(Request $request, Ad $ad)
    {
        if (! $this->canManage($request, $ad)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $ad->delete();

        return ApiResponseService::success(null, 'Ad deleted.');
    }

    private function typeForPage(?Page $page): string
    {
        return match ($page?->type) {
            Page::TYPE_BUSINESS => Ad::TYPE_BUSINESS,
            Page::TYPE_COMMUNITY => Ad::TYPE_COMMUNITY,
            default => Ad::TYPE_PRIVATE,
        };
    }

    private function canManage(Request $request, Ad $ad): bool
    {
        return $request->user()->id === $ad->user_id || $request->user()->hasRole('admin');
    }
}
