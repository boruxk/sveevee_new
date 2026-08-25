<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PagePrice;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class PagePriceController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function store(Request $request, Page $page)
    {
        if ($error = $this->guardBusinessPage($request, $page)) {
            return $error;
        }

        $data = $this->validated($request);
        $price = $page->prices()->create($data);

        return ApiResponseService::success($this->payloads->price($price), 'Price created.', 201);
    }

    public function update(Request $request, PagePrice $price)
    {
        $price->loadMissing('page');

        if ($error = $this->guardBusinessPage($request, $price->page)) {
            return $error;
        }

        $price->update($this->validated($request));

        return ApiResponseService::success($this->payloads->price($price->fresh()), 'Price saved.');
    }

    public function destroy(Request $request, PagePrice $price)
    {
        $price->loadMissing('page');

        if ($error = $this->guardBusinessPage($request, $price->page)) {
            return $error;
        }

        $price->delete();

        return ApiResponseService::success(null, 'Price deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', new CleanContent()],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
    }

    private function guardBusinessPage(Request $request, Page $page)
    {
        if ($page->user_id !== $request->user()->id) {
            return ApiResponseService::error('This action is unauthorized.', status: 403);
        }

        if ($page->type !== Page::TYPE_BUSINESS) {
            return ApiResponseService::error('Price lists are available only for business pages.', status: 422);
        }

        return null;
    }
}
