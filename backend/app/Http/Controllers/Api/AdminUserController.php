<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBan;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with(['profile', 'pages'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('given_name', 'like', $like)
                        ->orWhere('family_name', 'like', $like);
                });
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (User $user) => $this->payloads->user($user, includePrivate: true))
            ->values();

        return ApiResponseService::success($users);
    }

    public function show(User $user)
    {
        $user->load(['profile', 'pages.ads']);

        return ApiResponseService::success($this->payloads->user($user, includePrivate: true));
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
}
