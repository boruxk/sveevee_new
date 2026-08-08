<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class HomeFeedController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $profile = $request->user()->profile;
        $city = $this->nullableString($profile?->city);
        $neighborhood = $this->nullableString($profile?->neighborhood);
        $perPage = 20;
        $priorityCases = '';
        $priorityBindings = [];

        if ($city && $neighborhood) {
            $priorityCases .= 'when city = ? and neighborhood = ? then 0 ';
            $priorityBindings[] = $city;
            $priorityBindings[] = $neighborhood;
        }

        if ($city) {
            $priorityCases .= 'when city = ? then 1 ';
            $priorityBindings[] = $city;
        }

        $prioritySql = $priorityCases ? "case {$priorityCases}else 2 end" : '0';

        $ads = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderByRaw($prioritySql, $priorityBindings)
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponseService::success([
            'items' => $ads->getCollection()->map(fn (Ad $ad) => $this->payloads->ad($ad))->values(),
            'pagination' => [
                'current_page' => $ads->currentPage(),
                'last_page' => $ads->lastPage(),
                'per_page' => $ads->perPage(),
                'total' => $ads->total(),
            ],
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
