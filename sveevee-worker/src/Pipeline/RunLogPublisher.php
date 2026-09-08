<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Api\ApiException;
use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;

final class RunLogPublisher
{
    public function __construct(
        private readonly SveeveeGateway $api,
        private readonly WorkerRepository $repository,
        private readonly Logger $logger,
    ) {}

    public function publishPending(int $limit = 20): void
    {
        foreach ($this->repository->pendingRunLogs($limit) as $queued) {
            $runId = (string) $queued['run_id'];
            $this->repository->markRunLogAttempt($runId);

            try {
                $response = $this->api->reportRun($queued['report']);
                $this->repository->completeRunLog($runId);
                $this->logger->info('Worker run was added to the admin log.', [
                    'run_id' => $runId,
                    'replayed' => (bool) ($response['replayed'] ?? false),
                ]);
            } catch (\Throwable $exception) {
                $this->repository->failRunLog($runId, $exception->getMessage());
                $context = [
                    'run_id' => $runId,
                    'error' => $exception->getMessage(),
                ];
                if ($exception instanceof ApiException) {
                    $context['reason'] = $exception->reason;
                    $context['status'] = $exception->status;
                }
                $this->logger->warning(
                    'Worker run could not be added to the admin log; it will retry next run.',
                    $context,
                );
            }
        }
    }
}
