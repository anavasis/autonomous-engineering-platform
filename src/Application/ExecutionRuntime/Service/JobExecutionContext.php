<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Service;

use Aep\Application\ExecutionRuntime\Port\JobQueue;

/**
 * Per-execution context passed to JobHandlers (cancel checks + heartbeat).
 */
final class JobExecutionContext
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly string $jobId,
        private readonly string $workerId,
        private readonly int $leaseSeconds,
    ) {
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function isCancelRequested(): bool
    {
        return $this->queue->isCancelRequested($this->jobId);
    }

    public function heartbeat(string $nowUtc): bool
    {
        return $this->queue->heartbeat($this->jobId, $this->workerId, $this->leaseSeconds, $nowUtc);
    }
}
