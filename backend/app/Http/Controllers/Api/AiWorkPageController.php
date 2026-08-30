<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ExactPageDuplicateException;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\AiWorkPageService;
use App\Services\ApiResponseService;
use App\Services\PageDeletionService;
use App\Services\PageIdentityService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class AiWorkPageController extends Controller
{
    public function __construct(
        private readonly AiWorkPageService $pages,
        private readonly PageDeletionService $deletions,
        private readonly PageIdentityService $identities,
        private readonly PayloadService $payloads,
    ) {}

    public function index(Request $request)
    {
        $perPage = max(10, min(100, $request->integer('per_page', 25)));
        $query = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('is_unclaimed', true)
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->latest('updated_at');

        $paginator = $query->paginate($perPage);
        $items = collect($paginator->items())->map(fn (Page $page) => $this->pages->summary($page))->values();

        return ApiResponseService::success([
            'pages' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Page $page)
    {
        $this->ensureEditable($request, $page);

        return ApiResponseService::success($this->pagePayload($page));
    }

    public function store(Request $request)
    {
        $data = $this->pages->validate($request->all());

        try {
            $page = $this->pages->create($request->user(), $data);
        } catch (ExactPageDuplicateException $exception) {
            return $this->duplicateResponse($exception);
        }

        return ApiResponseService::success($this->pagePayload($page), 'Page created.', 201);
    }

    public function update(Request $request, Page $page)
    {
        $this->ensureEditable($request, $page);
        $data = $this->pages->validate($request->all());

        try {
            $page = $this->pages->update($page, $data);
        } catch (ExactPageDuplicateException $exception) {
            return $this->duplicateResponse($exception);
        }

        return ApiResponseService::success($this->pagePayload($page), 'Page updated.');
    }

    public function duplicateCheck(Request $request)
    {
        $data = $this->pages->validate($request->all());
        $excludeId = $request->integer('exclude_page_id') ?: null;
        $matches = $this->identities->exactMatches($data, $excludeId);

        return ApiResponseService::success([
            'exact_duplicate' => $matches->isNotEmpty(),
            'matches' => $matches,
        ]);
    }

    public function destroy(Request $request, Page $page)
    {
        $this->ensureEditable($request, $page);
        $this->deletions->delete($page);

        return ApiResponseService::success(null, 'Page deleted.');
    }

    private function pagePayload(Page $page): array
    {
        return $this->payloads->page($page->fresh([
            'user.profile', 'prices', 'products', 'services', 'events',
        ])->loadCount('ratings')->loadAvg('ratings', 'rating'));
    }

    private function ensureEditable(Request $request, Page $page): void
    {
        abort_unless($page->is_unclaimed && $page->user_id === $request->user()->id, 404);
    }

    private function duplicateResponse(ExactPageDuplicateException $exception)
    {
        return ApiResponseService::error(
            'An exact page duplicate already exists.',
            ['duplicate' => ['An exact page duplicate already exists.']],
            409,
            ['matches' => $exception->matches]
        );
    }
}
