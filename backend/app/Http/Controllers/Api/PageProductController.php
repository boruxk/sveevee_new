<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Models\Page;
use App\Models\PageProduct;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class PageProductController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function store(Request $request, Page $page)
    {
        if ($error = $this->guardBusinessPage($request, $page)) {
            return $error;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent()],
            'description' => ['required', 'string', 'max:5000', new CleanContent()],
            'image' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'link' => ['required', 'url', 'max:2048'],
        ]);

        $image = $request->file('image');

        $product = PageProduct::query()->create([
            'page_id' => $page->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'image_path' => $this->storePublicWebp($image, 'products', 'image'),
            'image_original_name' => $this->originalUploadName($request, 'image', $image),
            'price' => $data['price'],
            'link' => $data['link'],
        ]);

        return ApiResponseService::success($this->payloads->product($product), 'Product created.', 201);
    }

    public function update(Request $request, PageProduct $product)
    {
        $product->loadMissing('page');

        if ($error = $this->guardBusinessPage($request, $product->page)) {
            return $error;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000', new CleanContent()],
            'description' => ['required', 'string', 'max:5000', new CleanContent()],
            'image' => ['nullable', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
            'image_remove' => ['nullable', 'boolean'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'link' => ['required', 'url', 'max:2048'],
        ]);

        $product->fill([
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => $data['price'],
            'link' => $data['link'],
        ]);

        if ($request->boolean('image_remove') || $request->hasFile('image')) {
            $this->deletePublicUpload($product->image_path);
            $product->image_path = '';
            $product->image_original_name = null;
        }

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $product->image_path = $this->storePublicWebp($image, 'products', 'image');
            $product->image_original_name = $this->originalUploadName($request, 'image', $image);
        }

        $product->save();

        return ApiResponseService::success($this->payloads->product($product->fresh()), 'Product saved.');
    }

    public function destroy(Request $request, PageProduct $product)
    {
        $product->loadMissing('page');

        if ($error = $this->guardBusinessPage($request, $product->page)) {
            return $error;
        }

        $this->deletePublicUpload($product->image_path);
        $product->delete();

        return ApiResponseService::success(null, 'Product deleted.');
    }

    private function guardBusinessPage(Request $request, Page $page)
    {
        if ($page->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if ($page->type !== Page::TYPE_BUSINESS) {
            return ApiResponseService::error('Products are available only for business pages.', status: 422);
        }

        return null;
    }
}
