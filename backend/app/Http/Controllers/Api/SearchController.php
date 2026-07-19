<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Page;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return ApiResponseService::success([
                'users' => [],
                'pages' => [],
                'ads' => [],
            ]);
        }

        $like = '%'.$term.'%';

        $users = User::query()
            ->with(['profile', 'pages'])
            ->whereNull('banned_at')
            ->where(function ($query) use ($like): void {
                $query
                    ->where('name', 'like', $like)
                    ->orWhere('given_name', 'like', $like)
                    ->orWhere('family_name', 'like', $like);
            })
            ->limit(20)
            ->get()
            ->map(fn (User $user) => $this->payloads->user($user))
            ->values();

        $pages = Page::query()
            ->with(['user.profile'])
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->where(function ($query) use ($like): void {
                $query
                    ->where('name', 'like', $like)
                    ->orWhere('public_description', 'like', $like);
            })
            ->limit(20)
            ->get()
            ->map(fn (Page $page) => $this->payloads->page($page))
            ->values();

        $ads = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->where(function ($query) use ($like): void {
                $query
                    ->where('title', 'like', $like)
                    ->orWhere('text', 'like', $like);
            })
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Ad $ad) => $this->payloads->ad($ad))
            ->values();

        return ApiResponseService::success(compact('users', 'pages', 'ads'));
    }
}
