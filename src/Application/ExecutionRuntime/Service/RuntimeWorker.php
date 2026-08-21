<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Service;

use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Application\ExecutionRuntime\Port\JobHandler;
use Aep\Application\ExecutionRuntime\Port\JobQueue;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Claims and executes Runtime jobs. Supports graceful stop between jobs.
 */
final class RuntimeWorker
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    private bool $stopRequested = false;

    /**
     * @param list<JobHandler> $handlers
     */
    public function __construct(
        private readonly JobQueue $queue,
        array $handlers,
        private readonly int $leaseSeconds = 60,
        private readonly int $pollSleepUs = 200000,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * Process up to $maxJobs (null = until stop / empty queue once).
     *
     * @return int jobs processed
     */
    public function processAvailable(string $workerId, ?int $maxJobs = null): int
    {
        $processed = 0;
        while (!$this->stopRequested) {
            if ($maxJobs !== null && $processed >= $maxJobs) {
                break;
            }
            $this->queue->reclaimExpiredLeases(Utc::now());
            $job = $this->queue->claim($workerId, $this->leaseSeconds, Utc::now());
            if ($job === null) {
                break;
            }
            $this->executeClaimed($job, $workerId);
            $processed++;
        }

        return $processed;
    }

    /**
     * Long-running loop for bin/execution-worker.php
     */
    public function runForever(string $workerId): void
    {
        while (!$this->stopRequested) {
            $n = $this->processAvailable($workerId, 1);
            if ($n === 0 && !$this->stopRequested) {
                usleep(max(1, $this->pollSleepUs));
            }
        }
    }

    private function executeClaimed(RuntimeJob $job, string $workerId): void
    {
        $now = Utc::now();
        if ($job->cancelRequested()) {
            $this->queue->fail($job->id(), $workerId, $job->cancelReason() ?? 'Cancelled.', $now, false);
            // Move to cancelled via fail with requeue=false marks failed — use complete with cancelled status?
            // Prefer requestCancel already set; mark terminal cancelled through fail path that sets cancelled.
            return;
        }

        $handler = $this->handlers[$job->type()] ?? null;
        if ($handler === null) {
            $this->queue->fail(
                $job->id(),
                $workerId,
                'No handler registered for job type: ' . $job->type(),
                $now,
                false
            );

            return;
        }

        $ctx = new JobExecutionContext($this->queue, $job->id(), $workerId, $this->leaseSeconds);
        try {
            $result = $handler->execute($job, $ctx);
            if ($this->queue->isCancelRequested($job->id())) {
                $this->queue->fail(
                    $job->id(),
                    $workerId,
                    $job->cancelReason() ?? 'Cancelled during execution.',
                    Utc::now(),
                    false
                );

                return;
            }
            $this->queue->complete($job->id(), $workerId, $result, Utc::now());
        } catch (\Throwable $e) {
            $requeue = !$job->cancelRequested() && ($job->attempts() < $job->maxAttempts());
            // attempts already incremented on claim; compare carefully in queue.fail
            $this->queue->fail($job->id(), $workerId, $e->getMessage(), Utc::now(), $requeue);
        }
    }
}
