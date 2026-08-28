<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiWorkTask;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class AiWorkTaskController extends Controller
{
    public function index(Request $request)
    {
        $tasks = AiWorkTask::query()
            ->where('created_by_user_id', $request->user()->id)
            ->latest('updated_at')
            ->get();

        return ApiResponseService::success(['tasks' => $tasks]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $task = AiWorkTask::query()->create([
            ...$data,
            'created_by_user_id' => $request->user()->id,
        ]);

        return ApiResponseService::success($task, 'Task created.', 201);
    }

    public function update(Request $request, AiWorkTask $task)
    {
        $this->ensureOwner($request, $task);
        $task->update($this->validated($request));

        return ApiResponseService::success($task->fresh(), 'Task updated.');
    }

    public function destroy(Request $request, AiWorkTask $task)
    {
        $this->ensureOwner($request, $task);
        $task->delete();

        return ApiResponseService::success(null, 'Task deleted.');
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'title' => trim((string) $request->input('title')),
            'text' => trim((string) $request->input('text')),
        ]);

        return $request->validate([
            'title' => ['required', 'string', 'max:255', new CleanContent],
            'text' => ['required', 'string', 'max:10000', new CleanContent],
        ]);
    }

    private function ensureOwner(Request $request, AiWorkTask $task): void
    {
        abort_unless($task->created_by_user_id === $request->user()->id, 404);
    }
}
