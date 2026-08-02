<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Port;

use Aep\Application\ExecutionRuntime\Model\RuntimeJob;

interface JobQueue
{
    public function enqueue(RuntimeJob $job): void;

    public function get(string $jobId): ?RuntimeJob;

    /**
     * Atomically claim the next queued job for $workerId.
     * Returns null when the queue is empty.
     */
    public function claim(string $workerId, int $leaseSeconds, string $nowUtc): ?RuntimeJob;

    public function heartbeat(string $jobId, string $workerId, int $leaseSeconds, string $nowUtc): bool;

    /** @param array<string, mixed> $result */
    public function complete(string $jobId, string $workerId, array $result, string $nowUtc): void;

    public function fail(string $jobId, string $workerId, string $error, string $nowUtc, bool $requeue): void;

    public function requestCancel(string $jobId, string $reason, string $nowUtc): void;

    public function isCancelRequested(string $jobId): bool;

    /** Find latest job whose payload.runId matches. */
    public function findByRunId(string $runId): ?RuntimeJob;

    /**
     * Reclaim abandoned running jobs with expired leases.
     * Never requeues jobs that already executed successfully or are terminal.
     *
     * @return int number of reclaimed jobs
     */
    public function reclaimExpiredLeases(string $nowUtc): int;
}
