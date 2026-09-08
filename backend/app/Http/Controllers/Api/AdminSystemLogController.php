<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLogEntry;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class AdminSystemLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'source' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $logs = SystemLogEntry::query()
            ->when(filled($filters['source'] ?? null), fn ($query) => $query->where('source', $filters['source']))
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponseService::success([
            'items' => $logs->getCollection()
                ->map(fn (SystemLogEntry $entry): array => $this->payload($entry))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'filters' => [
                'sources' => SystemLogEntry::query()->distinct()->orderBy('source')->pluck('source')->all(),
                'types' => SystemLogEntry::query()->distinct()->orderBy('type')->pluck('type')->all(),
                'statuses' => SystemLogEntry::query()->distinct()->orderBy('status')->pluck('status')->all(),
            ],
        ]);
    }

    private function payload(SystemLogEntry $entry): array
    {
        return [
            'id' => $entry->uuid,
            'source' => $entry->source,
            'type' => $entry->type,
            'status' => $entry->status,
            'external_id' => $entry->external_id,
            'actor' => $entry->actor_type === null ? null : [
                'type' => $entry->actor_type,
                'identifier' => $entry->actor_identifier,
            ],
            'data' => $entry->data,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
