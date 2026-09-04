<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountNotificationService;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly AccountNotificationService $notifications) {}

    public function index(Request $request)
    {
        $perPage = min(30, max(1, $request->integer('per_page', 15)));
        $paginator = $request->user()
            ->notifications()
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return ApiResponseService::success([
            'items' => $paginator->getCollection()
                ->map(fn ($notification): array => $this->notifications->payload($notification))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            ...$this->notifications->summary($request->user()),
        ]);
    }

    public function read(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        return ApiResponseService::success([
            'notification' => $this->notifications->payload($notification->fresh()),
            ...$this->notifications->summary($request->user()),
        ]);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponseService::success($this->notifications->summary($request->user()));
    }
}
