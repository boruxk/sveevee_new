<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Page;
use App\Models\UserProfile;
use App\Services\ApiResponseService;

class LocationController extends Controller
{
    public function index()
    {
        $configuredLocations = collect(config('locations.cities', []))
            ->map(fn (array $location) => [
                'city' => $this->nullableString($location['name'] ?? null),
                'neighborhoods' => collect($location['neighborhoods'] ?? [])
                    ->map(fn ($neighborhood) => $this->nullableString($neighborhood))
                    ->filter()
                    ->values(),
            ])
            ->filter(fn (array $location) => filled($location['city']))
            ->values();

        $profiles = UserProfile::query()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->get(['city', 'neighborhood']);

        $ads = Ad::query()
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->get(['city', 'neighborhood']);

        $pages = Page::query()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->get(['setup'])
            ->map(fn (Page $page) => $this->pageAddress($page));

        $cities = $configuredLocations
            ->pluck('city')
            ->merge($profiles->pluck('city'))
            ->merge($ads->pluck('city'))
            ->merge($pages->pluck('city'))
            ->map(fn ($value) => $this->nullableString($value))
            ->filter()
            ->unique(fn (string $value) => mb_strtolower($value))
            ->sort(fn (string $left, string $right) => strcasecmp($left, $right))
            ->values();

        $neighborhoods = $configuredLocations
            ->flatMap(fn (array $location) => $location['neighborhoods']
                ->map(fn (string $neighborhood) => $this->neighborhoodPayload($location['city'], $neighborhood)))
            ->merge($profiles->map(fn ($location) => $this->neighborhoodPayload($location->city, $location->neighborhood)))
            ->merge($ads->map(fn ($location) => $this->neighborhoodPayload($location->city, $location->neighborhood)))
            ->merge($pages->map(fn (array $location) => $this->neighborhoodPayload($location['city'], $location['neighborhood'])))
            ->filter(fn (array $location) => filled($location['name']))
            ->unique(fn (array $location) => mb_strtolower(($location['city'] ?? '').'|'.$location['name']))
            ->sortBy(fn (array $location) => mb_strtolower(($location['city'] ?? '').'|'.$location['name']))
            ->values();

        return ApiResponseService::success([
            'cities' => $cities,
            'neighborhoods' => $neighborhoods,
        ]);
    }

    private function neighborhoodPayload(mixed $city, mixed $neighborhood): array
    {
        return [
            'city' => $this->nullableString($city),
            'name' => $this->nullableString($neighborhood),
        ];
    }

    private function pageAddress(Page $page): array
    {
        $setup = $page->setup ?? [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return [
            'city' => $this->nullableString($address['city'] ?? null),
            'neighborhood' => $this->nullableString($address['neighborhood'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
