<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageRating;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class PageRatingController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request, Page $page)
    {
        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $viewer = $request->user('sanctum');
        $ratings = $page->ratings()
            ->with('user.profile')
            ->latest()
            ->get();
        $myRating = $viewer
            ? $ratings->firstWhere('user_id', $viewer->id)
            : null;

        return ApiResponseService::success([
            'summary' => $this->payloads->pageRatingSummary($page),
            'items' => $ratings->map(fn (PageRating $rating) => $this->payloads->pageRating($rating))->values()->all(),
            'my_rating' => $myRating ? $this->payloads->pageRating($myRating) : null,
        ]);
    }

    public function store(Request $request, Page $page)
    {
        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        if ((int) $page->user_id === (int) $request->user()->id) {
            return ApiResponseService::error('You cannot rate your own page.', ['reason' => 'own_page'], 403);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = PageRating::query()->updateOrCreate(
            [
                'page_id' => $page->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $data['rating'],
                'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
            ]
        );

        $rating->load('user.profile');

        return ApiResponseService::success([
            'summary' => $this->payloads->pageRatingSummary($page),
            'rating' => $this->payloads->pageRating($rating),
        ], 'Rating saved.');
    }
}
