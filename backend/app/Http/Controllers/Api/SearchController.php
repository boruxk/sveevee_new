<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Page;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $city = $this->nullableString($request->query('city'));
        $neighborhood = $this->nullableString($request->query('neighborhood'));

        if ($term === '' && ! $city && ! $neighborhood) {
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
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('given_name', 'like', $like)
                        ->orWhere('family_name', 'like', $like);
                });
            })
            ->when($city || $neighborhood, function (Builder $query) use ($city, $neighborhood): void {
                $query->whereHas('profile', function (Builder $profile) use ($city, $neighborhood): void {
                    $profile
                        ->when($city, fn (Builder $profile) => $profile->where('city', $city))
                        ->when($neighborhood, fn (Builder $profile) => $profile->where('neighborhood', $neighborhood));
                });
            })
            ->limit(20)
            ->get()
            ->map(fn (User $user) => $this->payloads->user($user))
            ->values();

        $pages = Page::query()
            ->with(['user.profile'])
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('public_description', 'like', $like);
                });
            })
            ->when($city, function (Builder $query, string $city): void {
                $query->where(function (Builder $query) use ($city): void {
                    $query
                        ->where('setup->address->city', $city)
                        ->orWhere('address', 'like', '%'.$city.'%');
                });
            })
            ->when($neighborhood, function (Builder $query, string $neighborhood): void {
                $query->where('setup->address->neighborhood', $neighborhood);
            })
            ->limit(20)
            ->get()
            ->map(fn (Page $page) => $this->payloads->page($page))
            ->values();

        $ads = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('title', 'like', $like)
                        ->orWhere('text', 'like', $like);
                });
            })
            ->inLocation($city, $neighborhood)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Ad $ad) => $this->payloads->ad($ad))
            ->values();

        return ApiResponseService::success(compact('users', 'pages', 'ads'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
