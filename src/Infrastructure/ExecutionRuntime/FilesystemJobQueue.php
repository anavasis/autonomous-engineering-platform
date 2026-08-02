<?php

declare(strict_types=1);

namespace Aep\Infrastructure\ExecutionRuntime;

use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Application\ExecutionRuntime\Port\JobQueue;

/**
 * Filesystem job queue with atomic rename claim and lease reclaim.
 *
 * Layout:
 *   {root}/queued/{id}.json
 *   {root}/running/{id}.json
 *   {root}/completed/{id}.json
 *   {root}/failed/{id}.json
 *   {root}/index/by-run/{runId}.json
 *   {root}/locks/queue.lock
 */
final class FilesystemJobQueue implements JobQueue
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/queued',
            $this->root . '/running',
            $this->root . '/completed',
            $this->root . '/failed',
            $this->root . '/index/by-run',
            $this->root . '/locks',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create runtime queue dir: ' . $dir);
            }
        }
    }

    public function enqueue(RuntimeJob $job): void
    {
        $this->withLock(function () use ($job): void {
            if ($this->locate($job->id()) !== null) {
                throw new \RuntimeException('Job already exists: ' . $job->id());
            }
            $path = $this->root . '/queued/' . $job->id() . '.json';
            $this->writeJob($path, $job);
            $this->indexRun($job);
        });
    }

    public function get(string $jobId): ?RuntimeJob
    {
        $found = $this->locate($jobId);
        if ($found === null) {
            return null;
        }

        return $this->readJob($found['path']);
    }

    public function claim(string $workerId, int $leaseSeconds, string $nowUtc): ?RuntimeJob
    {
        return $this->withLock(function () use ($workerId, $leaseSeconds, $nowUtc): ?RuntimeJob {
            $queuedDir = $this->root . '/queued';
            $files = glob($queuedDir . '/*.json') ?: [];
            sort($files);
            foreach ($files as $path) {
                $job = $this->readJob($path);
                if ($job === null) {
                    continue;
                }
                if ($job->executed() || $job->isTerminal()) {
                    continue;
                }
                if ($job->cancelRequested()) {
                    $cancelled = $this->withStatus($job, RuntimeJob::STATUS_CANCELLED, $nowUtc, [
                        'error' => $job->cancelReason() ?? 'Cancelled.',
                        'leaseOwner' => null,
                        'leaseExpiresAtUtc' => null,
                    ]);
                    $this->moveAtomically($path, $this->root . '/failed/' . $job->id() . '.json', $cancelled);

                    continue;
                }

                $gen = $job->claimGeneration() + 1;
                $claimed = new RuntimeJob(
                    $job->id(),
                    $job->type(),
                    RuntimeJob::STATUS_RUNNING,
                    $job->payload(),
                    $job->attempts() + 1,
                    $job->maxAttempts(),
                    $job->createdAtUtc(),
                    $nowUtc,
                    $workerId,
                    $this->leaseExpiry($nowUtc, $leaseSeconds),
                    $nowUtc,
                    null,
                    null,
                    $job->cancelRequested(),
                    $job->cancelReason(),
                    $gen,
                    false,
                );
                $dest = $this->root . '/running/' . $job->id() . '.json';
                if (!$this->moveAtomically($path, $dest, $claimed)) {
                    continue;
                }
                $this->indexRun($claimed);

                return $claimed;
            }

            return null;
        });
    }

    public function heartbeat(string $jobId, string $workerId, int $leaseSeconds, string $nowUtc): bool
    {
        return (bool) $this->withLock(function () use ($jobId, $workerId, $leaseSeconds, $nowUtc): bool {
            $path = $this->root . '/running/' . $jobId . '.json';
            $job = is_file($path) ? $this->readJob($path) : null;
            if ($job === null || $job->leaseOwner() !== $workerId) {
                return false;
            }
            $updated = new RuntimeJob(
                $job->id(),
                $job->type(),
                $job->status(),
                $job->payload(),
                $job->attempts(),
                $job->maxAttempts(),
                $job->createdAtUtc(),
                $nowUtc,
                $workerId,
                $this->leaseExpiry($nowUtc, $leaseSeconds),
                $nowUtc,
                $job->result(),
                $job->error(),
                $job->cancelRequested(),
                $job->cancelReason(),
                $job->claimGeneration(),
                $job->executed(),
            );
            $this->writeJob($path, $updated);

            return true;
        });
    }

    public function complete(string $jobId, string $workerId, array $result, string $nowUtc): void
    {
        $this->withLock(function () use ($jobId, $workerId, $result, $nowUtc): void {
            $path = $this->root . '/running/' . $jobId . '.json';
            $job = is_file($path) ? $this->readJob($path) : null;
            if ($job === null) {
                throw new \RuntimeException('Cannot complete missing running job: ' . $jobId);
            }
            if ($job->leaseOwner() !== $workerId) {
                throw new \RuntimeException('Cannot complete job owned by another worker: ' . $jobId);
            }
            if ($job->executed()) {
                return; // idempotent
            }
            $done = new RuntimeJob(
                $job->id(),
                $job->type(),
                RuntimeJob::STATUS_COMPLETED,
                $job->payload(),
                $job->attempts(),
                $job->maxAttempts(),
                $job->createdAtUtc(),
                $nowUtc,
                null,
                null,
                $job->lastHeartbeatAtUtc(),
                $result,
                null,
                $job->cancelRequested(),
                $job->cancelReason(),
                $job->claimGeneration(),
                true,
            );
            $this->moveAtomically($path, $this->root . '/completed/' . $jobId . '.json', $done);
            $this->indexRun($done);
        });
    }

    public function fail(string $jobId, string $workerId, string $error, string $nowUtc, bool $requeue): void
    {
        $this->withLock(function () use ($jobId, $workerId, $error, $nowUtc, $requeue): void {
            $path = $this->root . '/running/' . $jobId . '.json';
            $job = is_file($path) ? $this->readJob($path) : null;
            if ($job === null) {
                return;
            }
            if ($job->leaseOwner() !== $workerId) {
                return;
            }
            if ($job->executed()) {
                return;
            }

            if ($job->cancelRequested()) {
                $cancelled = new RuntimeJob(
                    $job->id(),
                    $job->type(),
                    RuntimeJob::STATUS_CANCELLED,
                    $job->payload(),
                    $job->attempts(),
                    $job->maxAttempts(),
                    $job->createdAtUtc(),
                    $nowUtc,
                    null,
                    null,
                    $job->lastHeartbeatAtUtc(),
                    null,
                    $error,
                    true,
                    $job->cancelReason() ?? $error,
                    $job->claimGeneration(),
                    false,
                );
                $this->moveAtomically($path, $this->root . '/failed/' . $jobId . '.json', $cancelled);
                $this->indexRun($cancelled);

                return;
            }

            $canRetry = $requeue && $job->attempts() < $job->maxAttempts();
            if ($canRetry) {
                $queued = new RuntimeJob(
                    $job->id(),
                    $job->type(),
                    RuntimeJob::STATUS_QUEUED,
                    $job->payload(),
                    $job->attempts(),
                    $job->maxAttempts(),
                    $job->createdAtUtc(),
                    $nowUtc,
                    null,
                    null,
                    null,
                    null,
                    $error,
                    false,
                    null,
                    $job->claimGeneration(),
                    false,
                );
                $this->moveAtomically($path, $this->root . '/queued/' . $jobId . '.json', $queued);
                $this->indexRun($queued);

                return;
            }

            $failed = new RuntimeJob(
                $job->id(),
                $job->type(),
                RuntimeJob::STATUS_FAILED,
                $job->payload(),
                $job->attempts(),
                $job->maxAttempts(),
                $job->createdAtUtc(),
                $nowUtc,
                null,
                null,
                $job->lastHeartbeatAtUtc(),
                null,
                $error,
                $job->cancelRequested(),
                $job->cancelReason(),
                $job->claimGeneration(),
                false,
            );
            $this->moveAtomically($path, $this->root . '/failed/' . $jobId . '.json', $failed);
            $this->indexRun($failed);
        });
    }

    public function requestCancel(string $jobId, string $reason, string $nowUtc): void
    {
        $this->withLock(function () use ($jobId, $reason, $nowUtc): void {
            $found = $this->locate($jobId);
            if ($found === null) {
                return;
            }
            $job = $this->readJob($found['path']);
            if ($job === null || $job->isTerminal()) {
                return;
            }
            $updated = new RuntimeJob(
                $job->id(),
                $job->type(),
                $job->status(),
                $job->payload(),
                $job->attempts(),
                $job->maxAttempts(),
                $job->createdAtUtc(),
                $nowUtc,
                $job->leaseOwner(),
                $job->leaseExpiresAtUtc(),
                $job->lastHeartbeatAtUtc(),
                $job->result(),
                $job->error(),
                true,
                $reason,
                $job->claimGeneration(),
                $job->executed(),
            );
            if ($job->status() === RuntimeJob::STATUS_QUEUED) {
                $cancelled = $this->withStatus($updated, RuntimeJob::STATUS_CANCELLED, $nowUtc, [
                    'error' => $reason,
                    'leaseOwner' => null,
                    'leaseExpiresAtUtc' => null,
                ]);
                $this->moveAtomically($found['path'], $this->root . '/failed/' . $jobId . '.json', $cancelled);
                $this->indexRun($cancelled);

                return;
            }
            $this->writeJob($found['path'], $updated);
            $this->indexRun($updated);
        });
    }

    public function isCancelRequested(string $jobId): bool
    {
        $job = $this->get($jobId);

        return $job?->cancelRequested() === true;
    }

    public function findByRunId(string $runId): ?RuntimeJob
    {
        $index = $this->root . '/index/by-run/' . $this->safe($runId) . '.json';
        if (!is_file($index)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($index), true);
        $jobId = is_array($data) && is_string($data['jobId'] ?? null) ? $data['jobId'] : '';
        if ($jobId === '') {
            return null;
        }

        return $this->get($jobId);
    }

    public function reclaimExpiredLeases(string $nowUtc): int
    {
        return (int) $this->withLock(function () use ($nowUtc): int {
            $count = 0;
            $files = glob($this->root . '/running/*.json') ?: [];
            foreach ($files as $path) {
                $job = $this->readJob($path);
                if ($job === null) {
                    continue;
                }
                if ($job->executed() || $job->isTerminal()) {
                    continue;
                }
                $expires = $job->leaseExpiresAtUtc();
                if ($expires === null || strcmp($expires, $nowUtc) > 0) {
                    continue;
                }
                // Abandoned — never re-execute a job that already completed; only requeue if not executed.
                if ($job->cancelRequested()) {
                    $cancelled = $this->withStatus($job, RuntimeJob::STATUS_CANCELLED, $nowUtc, [
                        'error' => $job->cancelReason() ?? 'Cancelled (lease expired).',
                        'leaseOwner' => null,
                        'leaseExpiresAtUtc' => null,
                    ]);
                    $this->moveAtomically($path, $this->root . '/failed/' . $job->id() . '.json', $cancelled);
                    $this->indexRun($cancelled);
                    $count++;

                    continue;
                }
                if ($job->attempts() >= $job->maxAttempts()) {
                    $failed = $this->withStatus($job, RuntimeJob::STATUS_FAILED, $nowUtc, [
                        'error' => 'Lease expired; max attempts reached.',
                        'leaseOwner' => null,
                        'leaseExpiresAtUtc' => null,
                    ]);
                    $this->moveAtomically($path, $this->root . '/failed/' . $job->id() . '.json', $failed);
                    $this->indexRun($failed);
                    $count++;

                    continue;
                }
                $queued = new RuntimeJob(
                    $job->id(),
                    $job->type(),
                    RuntimeJob::STATUS_QUEUED,
                    $job->payload(),
                    $job->attempts(),
                    $job->maxAttempts(),
                    $job->createdAtUtc(),
                    $nowUtc,
                    null,
                    null,
                    null,
                    null,
                    'Lease expired; requeued.',
                    false,
                    null,
                    $job->claimGeneration(),
                    false,
                );
                $this->moveAtomically($path, $this->root . '/queued/' . $job->id() . '.json', $queued);
                $this->indexRun($queued);
                $count++;
            }

            return $count;
        });
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withLock(callable $fn): mixed
    {
        $lockPath = $this->root . '/locks/queue.lock';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open queue lock.');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire queue lock.');
            }

            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array{dir: string, path: string}|null */
    private function locate(string $jobId): ?array
    {
        foreach (['queued', 'running', 'completed', 'failed'] as $dir) {
            $path = $this->root . '/' . $dir . '/' . $jobId . '.json';
            if (is_file($path)) {
                return ['dir' => $dir, 'path' => $path];
            }
        }

        return null;
    }

    private function readJob(string $path): ?RuntimeJob
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        return RuntimeJob::fromArray($data);
    }

    private function writeJob(string $path, RuntimeJob $job): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($job->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write job file: ' . $path);
        }
    }

    private function moveAtomically(string $from, string $to, RuntimeJob $job): bool
    {
        $this->writeJob($from, $job);
        if (!rename($from, $to)) {
            return false;
        }

        return true;
    }

    private function indexRun(RuntimeJob $job): void
    {
        $runId = $job->payload()['runId'] ?? null;
        if (!is_string($runId) || $runId === '') {
            return;
        }
        $path = $this->root . '/index/by-run/' . $this->safe($runId) . '.json';
        file_put_contents($path, json_encode([
            'runId' => $runId,
            'jobId' => $job->id(),
            'status' => $job->status(),
            'updatedAtUtc' => $job->updatedAtUtc(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?? $id;
    }

    private function leaseExpiry(string $nowUtc, int $leaseSeconds): string
    {
        $ts = strtotime($nowUtc);
        if ($ts === false) {
            $ts = time();
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts + max(1, $leaseSeconds));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function withStatus(RuntimeJob $job, string $status, string $nowUtc, array $overrides = []): RuntimeJob
    {
        return new RuntimeJob(
            $job->id(),
            $job->type(),
            $status,
            $job->payload(),
            $job->attempts(),
            $job->maxAttempts(),
            $job->createdAtUtc(),
            $nowUtc,
            array_key_exists('leaseOwner', $overrides) ? ($overrides['leaseOwner'] !== null ? (string) $overrides['leaseOwner'] : null) : $job->leaseOwner(),
            array_key_exists('leaseExpiresAtUtc', $overrides) ? ($overrides['leaseExpiresAtUtc'] !== null ? (string) $overrides['leaseExpiresAtUtc'] : null) : $job->leaseExpiresAtUtc(),
            $job->lastHeartbeatAtUtc(),
            $job->result(),
            array_key_exists('error', $overrides) ? (is_string($overrides['error']) ? $overrides['error'] : null) : $job->error(),
            $job->cancelRequested() || $status === RuntimeJob::STATUS_CANCELLED,
            $job->cancelReason(),
            $job->claimGeneration(),
            $job->executed(),
        );
    }
}
