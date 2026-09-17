<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\BusinessPageClaimConflictService;
use App\Services\BusinessPageMatchService;
use App\Services\PageClaimService;
use App\Services\PageDeletionService;
use App\Services\PageFormDataService;
use App\Services\PageIdentityService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(
        private readonly PayloadService $payloads,
        private readonly PageDeletionService $deletions,
        private readonly BusinessPageMatchService $businessMatches,
        private readonly BusinessPageClaimConflictService $conflicts,
        private readonly PageClaimService $claims,
        private readonly PageFormDataService $forms,
        private readonly PageIdentityService $identities,
    ) {}

    public function mine(Request $request, string $type)
    {
        if ($request->user()->hasRole('ai_worker')) {
            return ApiResponseService::error('Use the AI works area to manage generated pages.', status: 403);
        }

        $this->validateType($type);

        $page = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->with(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->first();

        return ApiResponseService::success($page ? $this->payloads->page($page, withAds: true) : null);
    }

    public function upsert(Request $request, string $type)
    {
        if ($request->user()->hasRole('ai_worker')) {
            return ApiResponseService::error('Use the AI works area to manage generated pages.', status: 403);
        }

        $this->validateType($type);
        ['proposed' => $proposed, 'matching' => $matchingData, 'setup' => $setup, 'address' => $addressDetails]
            = $this->forms->validate($request, $type, $request->user()->email);
        $uploads = [];

        try {
            foreach (['logo' => 'pages/logos', 'banner' => 'pages/banners'] as $field => $directory) {
                if ($request->boolean($field.'_remove') || $request->hasFile($field)) {
                    $proposed[$field.'_path'] = null;
                    $proposed[$field.'_original_name'] = null;
                }
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $path = $this->storePublicWebp($file, $directory, $field);
                    $uploads[] = $path;
                    $proposed[$field.'_path'] = $path;
                    $proposed[$field.'_original_name'] = $this->originalUploadName($request, $field, $file);
                }
            }

            $save = fn (): array => DB::transaction(function () use ($request, $type, $proposed, $matchingData, $addressDetails): array {
                // Serialize new-page creation and conflict approval for the same requester.
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                $page = Page::query()->where('user_id', $user->id)->where('type', $type)->lockForUpdate()->first();
                $outcome = $page ? 'updated' : 'created';

                if ($page === null && $type === Page::TYPE_BUSINESS) {
                    $matches = $this->businessMatches->matches($matchingData);
                    $this->conflicts->cancelObsoleteSubmissions($user, $matches->pluck('page.id')->all());
                    if ($matches->count() > 1 || ($matches->count() === 1 && ! $matches->first()['page']->is_unclaimed)) {
                        $groupId = (string) Str::uuid();
                        $requests = $matches->map(fn (array $match): array => $this->claims->requestPayload(
                            $this->conflicts->submit($user, $match['page'], $proposed, $match['matched_on'], $groupId)
                        ))->all();

                        return ['save_outcome' => 'claim_conflict', 'claim_requests' => $requests, 'pending_claim' => true];
                    }
                    if ($matches->count() === 1) {
                        $page = $matches->first()['page'];
                        $page->forceFill(['user_id' => $user->id, 'is_unclaimed' => false, 'claimed_at' => now()]);
                        $outcome = 'adopted';
                    }
                }

                $page ??= new Page(['user_id' => $user->id, 'type' => $type]);
                $obsolete = $this->forms->fill($page, $proposed);
                $page->save();
                $page->ads()->update([
                    ...($outcome === 'adopted' ? ['user_id' => $user->id] : []),
                    'city' => $addressDetails['city'] ?? null,
                    'neighborhood' => $addressDetails['neighborhood'] ?? null,
                ]);
                if ($outcome === 'adopted') {
                    $this->claims->cancelCompetingRequests($page, $user);
                }

                return ['save_outcome' => $outcome, 'page' => $page, 'obsolete_media' => $obsolete];
            }, attempts: 3);

            // The name is mandatory for every match, including category-only second signals.
            // This lock also covers two users concurrently creating the same business.
            $result = $type === Page::TYPE_BUSINESS
                ? Cache::lock('business-page-save:'.hash('sha256', $this->identities->text($proposed['name'])), 60)->block(10,
                    fn (): array => $this->identities->withDuplicateLocks([
                        ...$matchingData, 'type' => $type, 'address' => $addressDetails, 'website' => $setup['website'],
                    ], $save))
                : $save();
        } catch (\Throwable $exception) {
            foreach ($uploads as $path) {
                $this->deletePublicUpload($path);
            }
            throw $exception;
        }

        if ($result['save_outcome'] === 'claim_conflict') {
            return ApiResponseService::success($result, 'Claim conflict sent to the administrator for review.', 202);
        }

        // A rollback must never leave the original page pointing at deleted images.
        $this->deletions->deleteMedia($result['obsolete_media']);
        $page = $result['page']->fresh(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])
            ->loadCount('ratings')->loadAvg('ratings', 'rating');

        return ApiResponseService::success([
            ...$this->payloads->page($page, withAds: true),
            'save_outcome' => $result['save_outcome'],
        ], 'Page saved.');
    }

    public function updateFeatures(Request $request, string $type)
    {
        if ($request->user()->hasRole('ai_worker')) {
            return ApiResponseService::error('Generated pages cannot enable modules.', status: 403);
        }

        $this->validateType($type);

        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.store' => ['nullable', 'boolean'],
            'features.services' => ['nullable', 'boolean'],
            'features.events' => ['nullable', 'boolean'],
            'features.price_list' => ['nullable', 'boolean'],
        ]);

        $page = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->firstOrFail();

        $features = $data['features'];
        $setup = is_array($page->setup) ? $page->setup : [];
        $setup['features'] = [
            'store' => $type === Page::TYPE_BUSINESS ? $this->forms->booleanValue($features['store'] ?? null, false) : false,
            'services' => $type === Page::TYPE_BUSINESS ? $this->forms->booleanValue($features['services'] ?? null, false) : false,
            'events' => $type === Page::TYPE_COMMUNITY ? $this->forms->booleanValue($features['events'] ?? null, false) : false,
            'price_list' => $type === Page::TYPE_BUSINESS ? $this->forms->booleanValue($features['price_list'] ?? null, false) : false,
        ];

        $page->setup = $this->forms->normalizedSetup($setup);
        $page->save();

        return ApiResponseService::success($this->payloads->page($page->fresh(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])->loadCount('ratings')->loadAvg('ratings', 'rating'), withAds: true), 'Page saved.');
    }

    public function show(Request $request, Page $page)
    {
        if ($page->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        $page->load(['user.profile', 'ads.user.profile', 'ads.page', 'prices', 'products', 'services', 'events'])
            ->loadCount('ratings')
            ->loadAvg('ratings', 'rating');

        $payload = $this->payloads->page($page, withAds: true);
        $viewer = $request->user('sanctum');
        $viewerClaim = $viewer
            ? PageClaimRequest::query()
                ->where('page_id', $page->id)
                ->where('user_id', $viewer->id)
                ->latest('created_at')
                ->latest('id')
                ->first()
            : null;
        $payload['viewer_claim'] = $viewerClaim ? [
            'id' => $viewerClaim->id,
            'status' => $viewerClaim->status,
            'created_at' => $viewerClaim->created_at?->toISOString(),
            'reviewed_at' => $viewerClaim->reviewed_at?->toISOString(),
        ] : null;

        return ApiResponseService::success($payload);
    }

    public function destroy(Request $request, Page $page)
    {
        if ($page->user_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->deletions->delete($page);

        return ApiResponseService::success(null, 'Page deleted.');
    }

    private function validateType(string $type): void
    {
        validator(['type' => $type], [
            'type' => ['required', Rule::in([Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])],
        ])->validate();
    }
}
