<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        // Imported address text stays on its business. Only reviewed localities become
        // shared choices; this endpoint must not hydrate the complete business catalog.
        $cities = $configuredLocations
            ->pluck('city')
            ->map(fn ($value) => $this->nullableString($value))
            ->filter()
            ->unique(fn (string $value) => mb_strtolower($value))
            ->sort(fn (string $left, string $right) => strcasecmp($left, $right))
            ->values();

        $neighborhoods = $configuredLocations
            ->flatMap(fn (array $location) => $location['neighborhoods']
                ->map(fn (string $neighborhood) => $this->neighborhoodPayload($location['city'], $neighborhood)))
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

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
