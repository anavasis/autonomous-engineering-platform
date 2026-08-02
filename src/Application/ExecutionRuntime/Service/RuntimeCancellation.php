<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Service;

use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\ExecutionRuntime\Port\JobQueue;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\MissionEngine;

/**
 * Runtime-aware mission cancel: disk job flag + MissionEngine cancel + EE session cancel.
 */
final class RuntimeCancellation
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly MissionEngine $engine,
        private readonly ?EngineeringExecutionOrchestrator $execution = null,
        private readonly ?ExecutionSessionStore $sessions = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelRun(string $runId, string $reason = 'Cancelled from Mission Control.'): array
    {
        $now = Utc::now();
        $job = $this->queue->findByRunId($runId);
        if ($job !== null && !$job->isTerminal()) {
            $this->queue->requestCancel($job->id(), $reason, $now);
        }

        $result = $this->engine->cancel($runId, $reason, $now);

        if ($this->execution !== null && $this->sessions !== null) {
            $session = $this->sessions->findLatestForMission($result->missionId());
            if ($session !== null && !$this->isTerminalSession($session->status())) {
                try {
                    $this->execution->cancel($session->sessionId(), $reason);
                } catch (\Throwable) {
                    // Best-effort EE cancel; mission cancel already persisted.
                }
            }
        }

        return [
            'runId' => $result->runId(),
            'missionId' => $result->missionId(),
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
            'runtimeJobId' => $job?->id(),
            'runtimeCancelRequested' => $job !== null,
        ];
    }

    private function isTerminalSession(string $status): bool
    {
        return in_array($status, [
            'succeeded',
            'failed',
            'rejected',
            'cancelled',
            'timed_out',
        ], true);
    }
}
