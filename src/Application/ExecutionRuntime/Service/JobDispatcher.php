<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Service;

use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Application\ExecutionRuntime\Port\JobQueue;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Enqueues generic Runtime jobs. Optionally runs inline (tests / single-process).
 */
final class JobDispatcher
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly RuntimeWorker $worker,
        private readonly bool $inline = false,
        private readonly int $defaultMaxAttempts = 3,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata arbitrary key/value pairs
     */
    public function enqueue(
        string $type,
        array $payload,
        ?int $maxAttempts = null,
        string $priority = JobPriority::NORMAL,
        array $metadata = [],
    ): RuntimeJob {
        $now = Utc::now();
        $job = new RuntimeJob(
            'job_' . bin2hex(random_bytes(8)),
            $type,
            RuntimeJob::STATUS_QUEUED,
            $priority,
            $payload,
            $metadata,
            0,
            $maxAttempts ?? $this->defaultMaxAttempts,
            $now,
            $now,
        );
        $this->queue->enqueue($job);

        if ($this->inline) {
            $this->worker->processAvailable('inline-' . getmypid(), 1);
        }

        return $job;
    }

    public function queue(): JobQueue
    {
        return $this->queue;
    }
}
