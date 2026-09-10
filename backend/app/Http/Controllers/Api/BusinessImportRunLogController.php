<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SystemLogConflictException;
use App\Http\Controllers\Controller;
use App\Models\SystemLogEntry;
use App\Services\ApiResponseService;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessImportRunLogController extends Controller
{
    public function __construct(private readonly SystemLogService $logs) {}

    public function store(Request $request)
    {
        $report = $request->validate([
            'run_id' => ['required', 'uuid'],
            'command' => ['required', Rule::in(['research', 'import', 'run'])],
            'dry_run' => ['required', 'boolean'],
            'status' => ['required', Rule::in(['completed', 'failed'])],
            'started_at' => ['required', 'date'],
            'finished_at' => ['required', 'date', 'after_or_equal:started_at'],
            'duration_seconds' => ['required', 'numeric', 'min:0', 'max:604800'],
            'found' => ['required', 'integer', 'min:0'],
            'new' => ['required', 'integer', 'min:0'],
            'existing' => ['required', 'integer', 'min:0'],
            'updated' => ['required', 'integer', 'min:0'],
            'duplicates' => ['required', 'integer', 'min:0'],
            'incomplete' => ['required', 'integer', 'min:0'],
            'failed' => ['required', 'integer', 'min:0'],
            'review' => ['sometimes', 'integer', 'min:0'],
            'source_requests' => ['sometimes', 'integer', 'min:0'],
            'source_errors' => ['sometimes', 'integer', 'min:0'],
            'imported' => ['required', 'integer', 'min:0'],
            'planned_imports' => ['required', 'integer', 'min:0'],
            'planned_updates' => ['required', 'integer', 'min:0'],
            'target_combinations' => ['required', 'integer', 'min:0', 'max:1000'],
            'scanned_target_combinations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'productive_target_combinations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'empty_target_combinations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'unproductive_target_combinations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'deferred_target_combinations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'overture_progress' => ['sometimes', 'array:release,total,scanned,remaining,pending,failed'],
            'overture_progress.release' => ['required_with:overture_progress', 'string', 'max:120'],
            'overture_progress.total' => ['required_with:overture_progress', 'integer', 'min:0'],
            'overture_progress.scanned' => ['required_with:overture_progress', 'integer', 'min:0', 'lte:overture_progress.total'],
            'overture_progress.remaining' => ['required_with:overture_progress', 'integer', 'min:0', 'lte:overture_progress.total'],
            'overture_progress.pending' => ['required_with:overture_progress', 'integer', 'min:0'],
            'overture_progress.failed' => ['required_with:overture_progress', 'integer', 'min:0'],
            'foursquare_progress' => ['sometimes', 'array:release,total,scanned,remaining,closed,invalid,pending,failed,review'],
            'foursquare_progress.release' => ['required_with:foursquare_progress', 'string', 'max:120'],
            'foursquare_progress.total' => ['required_with:foursquare_progress', 'integer', 'min:0'],
            'foursquare_progress.scanned' => ['required_with:foursquare_progress', 'integer', 'min:0', 'lte:foursquare_progress.total'],
            'foursquare_progress.remaining' => ['required_with:foursquare_progress', 'integer', 'min:0', 'lte:foursquare_progress.total'],
            'foursquare_progress.closed' => ['required_with:foursquare_progress', 'integer', 'min:0', 'lte:foursquare_progress.scanned'],
            'foursquare_progress.invalid' => ['sometimes', 'integer', 'min:0', 'lte:foursquare_progress.scanned'],
            'foursquare_progress.pending' => ['required_with:foursquare_progress', 'integer', 'min:0'],
            'foursquare_progress.failed' => ['required_with:foursquare_progress', 'integer', 'min:0'],
            'foursquare_progress.review' => ['required_with:foursquare_progress', 'integer', 'min:0'],
            'targets' => ['present', 'array', 'max:1000'],
            'targets.*.key' => ['required', 'string', 'max:255'],
            'targets.*.city' => ['required', 'string', 'max:160'],
            'targets.*.category_key' => ['required', 'string', 'max:160'],
            'targets.*.found' => ['required', 'integer', 'min:0'],
            'targets.*.successful' => ['sometimes', 'integer', 'min:0'],
            'targets.*.planned' => ['sometimes', 'integer', 'min:0'],
            'targets.*.deferred' => ['sometimes', 'boolean'],
            'used_sources' => ['present', 'array', 'max:50'],
            'used_sources.*' => ['required', 'string', 'max:120', 'distinct'],
            'source_counts' => ['present', 'array', 'max:50'],
            'source_counts.*' => ['required', 'integer', 'min:0'],
            'errors' => ['present', 'array', 'max:200'],
            'errors.*.stage' => ['required', 'string', 'max:120'],
            'errors.*.message' => ['required', 'string', 'max:2000'],
            'errors.*.context' => ['sometimes', 'array'],
        ]);
        $status = $report['status'] === 'failed'
            ? SystemLogEntry::STATUS_FAILED
            : (((int) $report['failed'] > 0 || (int) ($report['source_errors'] ?? 0) > 0 || (int) ($report['review'] ?? 0) > 0 || (int) $report['incomplete'] > 0 || $report['errors'] !== [])
                ? SystemLogEntry::STATUS_WARNING
                : SystemLogEntry::STATUS_SUCCESS);

        try {
            $outcome = $this->logs->record(
                SystemLogEntry::SOURCE_AUTOMATION_WORKER,
                SystemLogEntry::TYPE_BUSINESS_IMPORT_RUN,
                $report['run_id'],
                $status,
                $report,
                $report['finished_at'],
                'business_import_client',
                (string) $request->attributes->get('oauth_client_id'),
            );
        } catch (SystemLogConflictException $exception) {
            return ApiResponseService::error(
                $exception->getMessage(),
                ['run_id' => ['Use the original report or a new run_id.']],
                409
            );
        }

        $response = ApiResponseService::success([
            'id' => $outcome['entry']->uuid,
            'run_id' => $report['run_id'],
            'replayed' => $outcome['replayed'],
        ], $outcome['replayed'] ? 'Run report already logged.' : 'Run report logged.', $outcome['replayed'] ? 200 : 201);
        $response->headers->set('Idempotency-Replayed', $outcome['replayed'] ? 'true' : 'false');

        return $response;
    }
}
