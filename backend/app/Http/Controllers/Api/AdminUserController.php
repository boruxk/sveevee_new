<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBan;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use App\Services\UserDeletionService;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly PayloadService $payloads,
        private readonly UserDeletionService $userDeletion,
    ) {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $query = User::query()
            ->with(['profile', 'pages'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('login', 'like', $like)
                        ->orWhere('given_name', 'like', $like)
                        ->orWhere('family_name', 'like', $like);
                });
            })
            ->orderByDesc('created_at');

        if ($request->boolean('paginated')) {
            $perPage = min(50, max(1, $request->integer('per_page', 50)));
            $users = $query->paginate($perPage);

            return ApiResponseService::success([
                'items' => $users->getCollection()
                    ->map(fn (User $user) => $this->payloads->user($user, includePrivate: true))
                    ->values()
                    ->all(),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
                'total_users' => User::query()->count(),
            ]);
        }

        $users = $query
            ->limit(100)
            ->get()
            ->map(fn (User $user) => $this->payloads->user($user, includePrivate: true))
            ->values();

        return ApiResponseService::success($users);
    }

    public function show(User $user)
    {
        $user->load(['profile', 'pages.ads']);
        $payload = $this->payloads->user($user, includePrivate: true);
        $payload['login'] = $user->login;
        $payload['email_verified_at'] = $user->email_verified_at?->toISOString();
        $payload['banned_reason'] = $user->banned_reason;
        $payload['created_at'] = $user->created_at?->toISOString();
        $payload['updated_at'] = $user->updated_at?->toISOString();
        $payload['pages'] = $user->pages
            ->map(fn ($page) => $this->payloads->page($page, withAds: true))
            ->values()
            ->all();

        return ApiResponseService::success($payload);
    }

    public function ban(Request $request, User $user)
    {
        if ($user->hasRole('admin')) {
            return ApiResponseService::error('Admin users cannot be banned from this screen.', status: 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = $data['reason'] ?? 'Banned by admin.';

        $user->forceFill([
            'banned_at' => now(),
            'banned_reason' => $reason,
        ])->save();

        EmailBan::query()->updateOrCreate(
            ['email' => $user->email],
            [
                'banned_by_user_id' => $request->user()->id,
                'reason' => $reason,
                'banned_at' => now(),
            ]
        );

        $user->tokens()->delete();

        return ApiResponseService::success($this->payloads->user($user->fresh(['profile', 'pages']), includePrivate: true), 'User banned.');
    }

    public function restore(User $user)
    {
        $user->forceFill([
            'banned_at' => null,
            'banned_reason' => null,
        ])->save();

        EmailBan::query()->where('email', $user->email)->delete();

        return ApiResponseService::success($this->payloads->user($user->fresh(['profile', 'pages']), includePrivate: true), 'User restored.');
    }

    public function destroy(User $user)
    {
        if ($user->hasRole('admin')) {
            return ApiResponseService::error('Admin users cannot be deleted from this screen.', status: 422);
        }

        $deletedUserId = $user->id;
        $this->userDeletion->delete($user);

        return ApiResponseService::success(['id' => $deletedUserId], 'User deleted.');
    }
}
