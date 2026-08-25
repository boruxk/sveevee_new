<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Models\Ad;
use App\Models\Page;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Support\CatalogTopics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $city = $this->nullableString($request->query('city'));
        $neighborhood = $this->nullableString($request->query('neighborhood'));

        $query = Ad::query()
            ->with(['user.profile', 'page'])
            ->active()
            ->whereHas('user', fn ($inner) => $inner->whereNull('banned_at'))
            ->inLocation($city, $neighborhood)
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

    public function show(Ad $ad)
    {
        $ad->loadMissing(['user.profile', 'page']);

        if (! $ad->isVisible() || $ad->user?->banned_at) {
            return ApiResponseService::error('Resource not found.', status: 404);
        }

        return ApiResponseService::success($this->payloads->ad($ad));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:1000', new CleanContent()],
            'text' => ['required', 'string', 'max:5000', new CleanContent()],
            'category' => $this->categoryRules(),
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'image' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
        ]);

        $page = null;
        if (! empty($data['page_id'])) {
            $page = Page::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($data['page_id']);
        }

        $location = $this->locationFor($page, $request->user());

        $image = $request->file('image');

        $ad = Ad::query()->create([
            'user_id' => $request->user()->id,
            'page_id' => $page?->id,
            'type' => $this->typeForPage($page),
            'title' => $data['title'],
            'text' => $data['text'],
            'category' => $data['category'] ?? null,
            'image_path' => $image ? $this->storePublicWebp($image, 'ads', 'image') : null,
            'image_original_name' => $image ? $this->originalUploadName($request, 'image', $image) : null,
            'status' => 'active',
            'expires_at' => now()->addWeek(),
            'city' => $location['city'],
            'neighborhood' => $location['neighborhood'],
        ]);

        return ApiResponseService::success($this->payloads->ad($ad), 'Ad created.', 201);
    }

    public function update(Request $request, Ad $ad)
    {
        if (! $this->canManage($request, $ad)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:1000', new CleanContent()],
            'text' => ['required', 'string', 'max:5000', new CleanContent()],
            'category' => $this->categoryRules(),
            'status' => ['nullable', Rule::in(['active', 'paused'])],
            'image' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'image_remove' => ['nullable', 'boolean'],
        ]);

        $ad->loadMissing(['page', 'user.profile']);
        $location = $this->locationFor($ad->page, $ad->user);

        $ad->fill([
            'title' => $data['title'],
            'text' => $data['text'],
            'category' => array_key_exists('category', $data) ? $data['category'] : $ad->category,
            'status' => $data['status'] ?? $ad->status,
            'city' => $location['city'],
            'neighborhood' => $location['neighborhood'],
        ]);

        if ($request->boolean('image_remove') || $request->hasFile('image')) {
            $this->deletePublicUpload($ad->image_path);
            $ad->image_path = null;
            $ad->image_original_name = null;
        }

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $ad->image_path = $this->storePublicWebp($image, 'ads', 'image');
            $ad->image_original_name = $this->originalUploadName($request, 'image', $image);
        }

        $ad->save();

        return ApiResponseService::success($this->payloads->ad($ad->fresh()), 'Ad saved.');
    }

    public function destroy(Request $request, Ad $ad)
    {
        if (! $this->canManage($request, $ad)) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        $this->deletePublicUpload($ad->image_path);
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

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function categoryRules(): array
    {
        return [
            'nullable',
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! CatalogTopics::isAdCategoryValue((string) $value)) {
                    $fail('The selected category is invalid.');
                }
            },
        ];
    }

    private function locationFor(?Page $page, ?User $user): array
    {
        if ($page) {
            return [
                'city' => $this->pageAddressValue($page, 'city'),
                'neighborhood' => $this->pageAddressValue($page, 'neighborhood'),
            ];
        }

        $user?->loadMissing('profile');

        return [
            'city' => $this->nullableString($user?->profile?->city),
            'neighborhood' => $this->nullableString($user?->profile?->neighborhood),
        ];
    }

    private function pageAddressValue(Page $page, string $field): ?string
    {
        $setup = $page?->setup ?? [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return $this->nullableString($address[$field] ?? null);
    }
}
