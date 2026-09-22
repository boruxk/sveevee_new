<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityReport;
use App\Services\ApiResponseService;
use App\Services\CommunityContentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCommunityReportController extends Controller
{
    public function __construct(private readonly CommunityContentService $content) {}

    public function index(Request $request)
    {
        if ($request->user()->banned_at) {
            throw new AuthorizationException;
        }
        $data = $request->validate(['status' => ['nullable', 'in:pending,dismissed,hidden,all'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $status = $data['status'] ?? 'all';
        $rows = CommunityReport::query()->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with('user.profile')->latest('id')->paginate($data['per_page'] ?? 20);

        return ApiResponseService::success(['items' => $rows->getCollection()->map(fn ($r) => $this->payload($r))->values(), 'pagination' => [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
        ]]);
    }

    public function update(Request $request, int $id)
    {
        if ($request->user()->banned_at) {
            throw new AuthorizationException;
        }
        $data = $request->validate(['action' => ['required', 'in:dismiss,hide']]);
        $report = DB::transaction(function () use ($request, $id, $data) {
            $report = CommunityReport::query()->lockForUpdate()->findOrFail($id);
            if ($report->status !== 'pending') {
                return $report;
            }
            if ($data['action'] === 'hide') {
                $target = $this->content->query($report->target_type, true)->lockForUpdate()->find($report->target_id);
                if ($target) {
                    $field = in_array($report->target_type, ['ad', 'event', 'product', 'service'], true) ? 'community_hidden_at' : 'hidden_at';
                    $target->forceFill([$field => now()])->save();
                }
                CommunityReport::where('target_type', $report->target_type)->where('target_id', $report->target_id)->where('status', 'pending')
                    ->update(['status' => 'hidden', 'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now()]);

                return $report->fresh();
            }
            $report->update(['status' => 'dismissed', 'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now()]);

            return $report;
        }, 3);

        return ApiResponseService::success($this->payload($report));
    }

    private function payload(CommunityReport $report): array
    {
        $target = $this->content->query($report->target_type, true)->find($report->target_id);
        $path = null;
        if ($target) {
            try {
                $path = $this->content->path($report->target_type, $target);
            } catch (ModelNotFoundException) {
            }
        }

        return [
            'id' => $report->id, 'reason' => $report->reason, 'status' => $report->status, 'created_at' => $report->created_at?->toISOString(),
            'reporter' => $this->content->actor($report->user), 'reviewed_at' => $report->reviewed_at?->toISOString(),
            'target' => ['type' => $report->target_type, 'id' => $report->target_id,
                'title' => $target?->title ?? $target?->name ?? '', 'body' => $target?->body ?? $target?->text ?? $target?->description ?? '',
                'public_path' => $path, 'hidden' => ! $target || (bool) ($target->hidden_at ?? $target->community_hidden_at)],
        ];
    }
}
