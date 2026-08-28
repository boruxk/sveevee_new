<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageService;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Support\CatalogTopics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageServiceController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(private readonly PayloadService $payloads) {}

    public function store(Request $request, Page $page)
    {
        if ($error = $this->guardBusinessPage($request, $page)) {
            return $error;
        }

        $this->normalizeCategoryKey($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent],
            'description' => ['required', 'string', 'max:5000', new CleanContent],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope(CatalogTopics::SCOPE_SERVICES))],
            'image' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'link' => ['nullable', 'url', 'max:2048'],
        ]);

        $image = $request->file('image');

        $service = PageService::query()->create([
            'page_id' => $page->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'category_key' => $data['category_key'],
            'image_path' => $this->storePublicWebp($image, 'services', 'image'),
            'image_original_name' => $this->originalUploadName($request, 'image', $image),
            'link' => $data['link'] ?? null,
        ]);

        return ApiResponseService::success($this->payloads->service($service), 'Service created.', 201);
    }

    public function update(Request $request, PageService $service)
    {
        $service->loadMissing('page');

        if ($error = $this->guardBusinessPage($request, $service->page)) {
            return $error;
        }

        $this->normalizeCategoryKey($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent],
            'description' => ['required', 'string', 'max:5000', new CleanContent],
            'category_key' => ['required', 'string', Rule::in(CatalogTopics::keysForScope(CatalogTopics::SCOPE_SERVICES))],
            'image' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'image_remove' => ['nullable', 'boolean'],
            'link' => ['nullable', 'url', 'max:2048'],
        ]);

        $service->fill([
            'name' => $data['name'],
            'description' => $data['description'],
            'category_key' => $data['category_key'],
            'link' => $data['link'] ?? null,
        ]);

        if ($request->boolean('image_remove') || $request->hasFile('image')) {
            $this->deletePublicUpload($service->image_path);
            $service->image_path = '';
            $service->image_original_name = null;
        }

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $service->image_path = $this->storePublicWebp($image, 'services', 'image');
            $service->image_original_name = $this->originalUploadName($request, 'image', $image);
        }

        $service->save();

        return ApiResponseService::success($this->payloads->service($service->fresh()), 'Service saved.');
    }

    public function destroy(Request $request, PageService $service)
    {
        $service->loadMissing('page');

        if ($error = $this->guardBusinessPage($request, $service->page)) {
            return $error;
        }

        $this->deletePublicUpload($service->image_path);
        $service->delete();

        return ApiResponseService::success(null, 'Service deleted.');
    }

    private function guardBusinessPage(Request $request, Page $page)
    {
        if ($page->is_unclaimed || $page->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if ($page->type !== Page::TYPE_BUSINESS) {
            return ApiResponseService::error('Services are available only for business pages.', status: 422);
        }

        return null;
    }

    private function normalizeCategoryKey(Request $request): void
    {
        $key = trim((string) $request->input('category_key', ''));

        if ($key === '') {
            return;
        }

        $request->merge([
            'category_key' => CatalogTopics::canonicalKeyForScope($key, CatalogTopics::SCOPE_SERVICES) ?? $key,
        ]);
    }
}
