<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PageClaimService;
use App\Services\PageDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPageController extends Controller
{
    public function __construct(
        private readonly PageClaimService $claims,
        private readonly PageDeletionService $deletions,
    ) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $ownership = trim((string) $request->query('ownership', ''));
        $perPage = min(100, max(1, $request->integer('per_page', 50)));

        $pages = Page::query()
            ->with(['user.profile'])
            ->withCount(['ads', 'products', 'services', 'events'])
            ->when(in_array($type, [Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY], true), fn ($query) => $query->where('type', $type))
            ->when($ownership === 'managed', fn ($query) => $query->where('is_unclaimed', false))
            ->when($ownership === 'unclaimed', fn ($query) => $query->where('is_unclaimed', true))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner
                        ->where('name', 'like', $like)
                        ->orWhere('address', 'like', $like)
                        ->orWhere('contact_email', 'like', $like)
                        ->orWhereHas('user', function ($user) use ($like): void {
                            $user
                                ->where('name', 'like', $like)
                                ->orWhere('given_name', 'like', $like)
                                ->orWhere('family_name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->latest('updated_at')
            ->paginate($perPage);

        return ApiResponseService::success([
            'items' => $pages->getCollection()->map(fn (Page $page): array => $this->pagePayload($page))->values()->all(),
            'pagination' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
            ],
            'total_pages' => Page::query()->count(),
        ]);
    }

    public function ownerOptions(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->where('role', 'user')
            ->whereNull('banned_at')
            ->with([
                'profile',
                'pages' => fn ($query) => $query
                    ->where('is_unclaimed', false)
                    ->select(['id', 'user_id', 'type']),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner
                        ->where('name', 'like', $like)
                        ->orWhere('given_name', 'like', $like)
                        ->orWhere('family_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('login', 'like', $like);
                });
            })
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'city' => $user->profile?->city,
                'page_ids_by_type' => $user->pages
                    ->mapWithKeys(fn (Page $page): array => [$page->type => $page->id])
                    ->all(),
            ])
            ->values();

        return ApiResponseService::success(['items' => $users]);
    }

    public function updateOwner(Request $request, Page $page)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $targetUserId = isset($data['user_id']) ? (int) $data['user_id'] : null;

        $updated = DB::transaction(function () use ($request, $page, $targetUserId): Page {
            $lockedPage = Page::query()->lockForUpdate()->findOrFail($page->id);

            if ($targetUserId === null) {
                $worker = User::query()
                    ->where('role', 'ai_worker')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $worker) {
                    throw ValidationException::withMessages([
                        'user_id' => ['The AI works account is not available.'],
                    ]);
                }

                $lockedPage->forceFill([
                    'user_id' => $worker->id,
                    'is_unclaimed' => true,
                    'claimed_at' => null,
                ])->save();
                $lockedPage->ads()->update(['user_id' => $worker->id]);

                return $lockedPage;
            }

            $targetUser = User::query()->lockForUpdate()->findOrFail($targetUserId);

            if (! $targetUser->hasRole('user') || $targetUser->banned_at) {
                throw ValidationException::withMessages([
                    'user_id' => ['Pages can only be assigned to active regular users.'],
                ]);
            }

            $conflictingPage = Page::query()
                ->where('user_id', $targetUser->id)
                ->where('type', $lockedPage->type)
                ->where('is_unclaimed', false)
                ->where('id', '!=', $lockedPage->id)
                ->exists();

            if ($conflictingPage) {
                throw ValidationException::withMessages([
                    'user_id' => ["This user already manages a {$lockedPage->type} page."],
                ]);
            }

            $lockedPage->forceFill([
                'user_id' => $targetUser->id,
                'is_unclaimed' => false,
                'claimed_at' => $lockedPage->is_unclaimed ? now() : ($lockedPage->claimed_at ?: now()),
            ])->save();
            $lockedPage->ads()->update(['user_id' => $targetUser->id]);
            $this->resolvePendingClaims($lockedPage, $targetUser, $request->user());

            return $lockedPage;
        });

        $updated->load(['user.profile'])->loadCount(['ads', 'products', 'services', 'events']);

        return ApiResponseService::success(
            $this->pagePayload($updated),
            $targetUserId === null ? 'Page detached from its user.' : 'Page assigned to user.'
        );
    }

    public function destroy(Page $page)
    {
        $this->deletions->delete($page);

        return ApiResponseService::success(null, 'Page permanently deleted.');
    }

    private function resolvePendingClaims(Page $page, User $targetUser, User $admin): void
    {
        $pendingClaims = PageClaimRequest::query()
            ->with('conversation')
            ->where('page_id', $page->id)
            ->where('status', PageClaimRequest::STATUS_PENDING)
            ->lockForUpdate()
            ->get();

        foreach ($pendingClaims as $claim) {
            $approved = $claim->user_id === $targetUser->id;
            $claim->setRelation('page', $page);
            $claim->forceFill([
                'status' => $approved ? PageClaimRequest::STATUS_APPROVED : PageClaimRequest::STATUS_CANCELLED,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            if ($claim->conversation) {
                $this->claims->appendMessage(
                    $claim->conversation,
                    $admin,
                    $this->claims->reviewedMarker($claim, $approved)
                );
            }
        }
    }

    private function pagePayload(Page $page): array
    {
        $address = is_array($page->setup) ? ($page->setup['address'] ?? []) : [];

        return [
            'id' => $page->id,
            'name' => $page->name,
            'type' => $page->type,
            'is_unclaimed' => (bool) $page->is_unclaimed,
            'public_path' => $page->public_path,
            'category_key' => $page->category_key,
            'city' => $address['city'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
            'owner' => ! $page->is_unclaimed && $page->user ? [
                'id' => $page->user->id,
                'display_name' => $page->user->display_name,
                'email' => $page->user->email,
                'city' => $page->user->profile?->city,
            ] : null,
            'counts' => [
                'ads' => (int) ($page->ads_count ?? 0),
                'products' => (int) ($page->products_count ?? 0),
                'services' => (int) ($page->services_count ?? 0),
                'events' => (int) ($page->events_count ?? 0),
            ],
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }
}
