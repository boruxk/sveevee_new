<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageProduct;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class PageProductController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function store(Request $request, Page $page)
    {
        if ($page->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if ($page->type !== Page::TYPE_BUSINESS) {
            return ApiResponseService::error('Products are available only for business pages.', status: 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:3000'],
            'image' => ['required', 'image', 'max:6144'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'link' => ['required', 'url', 'max:2048'],
        ]);

        $product = PageProduct::query()->create([
            'page_id' => $page->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'image_path' => $request->file('image')->store('products', 'public'),
            'price' => $data['price'],
            'link' => $data['link'],
        ]);

        return ApiResponseService::success($this->payloads->product($product), 'Product created.', 201);
    }
}
