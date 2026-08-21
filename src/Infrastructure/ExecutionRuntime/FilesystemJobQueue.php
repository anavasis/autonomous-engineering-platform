<?php

declare(strict_types=1);

namespace Aep\Infrastructure\ExecutionRuntime;

use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Model\RuntimeEvent;
use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Application\ExecutionRuntime\Port\JobQueue;
use Aep\Application\ExecutionRuntime\Port\NullRuntimeEventStore;
use Aep\Application\ExecutionRuntime\Port\RuntimeEventStore;

/**
 * Filesystem job queue with atomic rename claim and lease reclaim.
 *
 * Layout:
 *   {root}/queued/{id}.json
 *   {root}/running/{id}.json
 *   {root}/completed/{id}.json
 *   {root}/failed/{id}.json
 *   {root}/events/{id}.jsonl
 *   {root}/index/by-run/{runId}.json
 *   {root}/locks/queue.lock
 *
 * Claim order: priority DESC, createdAt ASC.
 */
final class FilesystemJobQueue implements JobQueue
{
    private readonly string $root;
    private readonly RuntimeEventStore $events;

    public function __construct(string $root, ?RuntimeEventStore $events = null)
    {
        $this->root = rtrim($root, "/\\");
        $this->events = $events ?? new NullRuntimeEventStore();
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
            $this->emit($job->id(), RuntimeEvent::JOB_QUEUED, $job->createdAt(), [
                'type' => $job->type(),
                'priority' => $job->priority(),
                'metadata' => $job->metadata(),
            ]);
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
            $candidates = $this->listQueuedSorted();
            foreach ($candidates as $path) {
                $job = $this->readJob($path);
                if ($job === null) {
                    continue;
                }
                if ($job->executed() || $job->isTerminal()) {
                    continue;
                }
                if ($job->cancelRequested()) {
                    $cancelled = $job->with([
                        'status' => RuntimeJob::STATUS_CANCELLED,
                        'updatedAt' => $nowUtc,
                        'error' => $job->cancelReason() ?? 'Cancelled.',
                        'leaseOwner' => null,
                        'leaseExpiresAt' => null,
                        'cancelRequested' => true,
                    ]);
                    $this->moveAtomically($path, $this->root . '/failed/' . $job->id() . '.json', $cancelled);
                    $this->emit($job->id(), RuntimeEvent::CANCELLED, $nowUtc, [
                        'reason' => $cancelled->error(),
                    ]);

                    continue;
                }

                $claimed = $job->with([
                    'status' => RuntimeJob::STATUS_RUNNING,
                    'attempts' => $job->attempts() + 1,
                    'updatedAt' => $nowUtc,
                    'leaseOwner' => $workerId,
                    'leaseExpiresAt' => $this->leaseExpiry($nowUtc, $leaseSeconds),
                    'lastHeartbeatAt' => $nowUtc,
                    'result' => null,
                    'error' => null,
                    'claimGeneration' => $job->claimGeneration() + 1,
                    'executed' => false,
                ]);
                $dest = $this->root . '/running/' . $job->id() . '.json';
                if (!$this->moveAtomically($path, $dest, $claimed)) {
                    continue;
                }
                $this->indexRun($claimed);
                $this->emit($claimed->id(), RuntimeEvent::JOB_CLAIMED, $nowUtc, [
                    'workerId' => $workerId,
                    'attempts' => $claimed->attempts(),
                    'leaseExpiresAt' => $claimed->leaseExpiresAt(),
                    'priority' => $claimed->priority(),
                ]);

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
            $leaseExpiresAt = $this->leaseExpiry($nowUtc, $leaseSeconds);
            $updated = $job->with([
                'updatedAt' => $nowUtc,
                'leaseOwner' => $workerId,
                'leaseExpiresAt' => $leaseExpiresAt,
                'lastHeartbeatAt' => $nowUtc,
            ]);
            $this->writeJob($path, $updated);
            $this->emit($jobId, RuntimeEvent::HEARTBEAT, $nowUtc, [
                'workerId' => $workerId,
                'leaseExpiresAt' => $leaseExpiresAt,
            ]);
            $this->emit($jobId, RuntimeEvent::LEASE_RENEWED, $nowUtc, [
                'workerId' => $workerId,
                'leaseExpiresAt' => $leaseExpiresAt,
            ]);

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
            $done = $job->with([
                'status' => RuntimeJob::STATUS_COMPLETED,
                'updatedAt' => $nowUtc,
                'leaseOwner' => null,
                'leaseExpiresAt' => null,
                'result' => $result,
                'error' => null,
                'executed' => true,
            ]);
            $this->moveAtomically($path, $this->root . '/completed/' . $jobId . '.json', $done);
            $this->indexRun($done);
            $this->emit($jobId, RuntimeEvent::COMPLETED, $nowUtc, [
                'workerId' => $workerId,
            ]);
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
                $cancelled = $job->with([
                    'status' => RuntimeJob::STATUS_CANCELLED,
                    'updatedAt' => $nowUtc,
                    'leaseOwner' => null,
                    'leaseExpiresAt' => null,
                    'result' => null,
                    'error' => $error,
                    'cancelRequested' => true,
                    'cancelReason' => $job->cancelReason() ?? $error,
                    'executed' => false,
                ]);
                $this->moveAtomically($path, $this->root . '/failed/' . $jobId . '.json', $cancelled);
                $this->indexRun($cancelled);
                $this->emit($jobId, RuntimeEvent::CANCELLED, $nowUtc, [
                    'reason' => $cancelled->cancelReason(),
                    'workerId' => $workerId,
                ]);

                return;
            }

            $canRetry = $requeue && $job->attempts() < $job->maxAttempts();
            if ($canRetry) {
                $queued = $job->with([
                    'status' => RuntimeJob::STATUS_QUEUED,
                    'updatedAt' => $nowUtc,
                    'leaseOwner' => null,
                    'leaseExpiresAt' => null,
                    'lastHeartbeatAt' => null,
                    'result' => null,
                    'error' => $error,
                    'cancelRequested' => false,
                    'cancelReason' => null,
                    'executed' => false,
                ]);
                $this->moveAtomically($path, $this->root . '/queued/' . $jobId . '.json', $queued);
                $this->indexRun($queued);
                $this->emit($jobId, RuntimeEvent::RETRY_SCHEDULED, $nowUtc, [
                    'error' => $error,
                    'attempts' => $queued->attempts(),
                    'maxAttempts' => $queued->maxAttempts(),
                    'workerId' => $workerId,
                ]);

                return;
            }

            $failed = $job->with([
                'status' => RuntimeJob::STATUS_FAILED,
                'updatedAt' => $nowUtc,
                'leaseOwner' => null,
                'leaseExpiresAt' => null,
                'result' => null,
                'error' => $error,
                'executed' => false,
            ]);
            $this->moveAtomically($path, $this->root . '/failed/' . $jobId . '.json', $failed);
            $this->indexRun($failed);
            $this->emit($jobId, RuntimeEvent::FAILED, $nowUtc, [
                'error' => $error,
                'workerId' => $workerId,
                'attempts' => $failed->attempts(),
            ]);
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
            $updated = $job->with([
                'updatedAt' => $nowUtc,
                'cancelRequested' => true,
                'cancelReason' => $reason,
            ]);
            if ($job->status() === RuntimeJob::STATUS_QUEUED) {
                $cancelled = $updated->with([
                    'status' => RuntimeJob::STATUS_CANCELLED,
                    'error' => $reason,
                    'leaseOwner' => null,
                    'leaseExpiresAt' => null,
                ]);
                $this->moveAtomically($found['path'], $this->root . '/failed/' . $jobId . '.json', $cancelled);
                $this->indexRun($cancelled);
                $this->emit($jobId, RuntimeEvent::CANCELLED, $nowUtc, ['reason' => $reason]);

                return;
            }
            $this->writeJob($found['path'], $updated);
            $this->indexRun($updated);
            // Cancel acknowledged; terminal Cancelled event emitted when worker completes cancel path.
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
                $expires = $job->leaseExpiresAt();
                if ($expires === null || strcmp($expires, $nowUtc) > 0) {
                    continue;
                }
                if ($job->cancelRequested()) {
                    $cancelled = $job->with([
                        'status' => RuntimeJob::STATUS_CANCELLED,
                        'updatedAt' => $nowUtc,
                        'error' => $job->cancelReason() ?? 'Cancelled (lease expired).',
                        'leaseOwner' => null,
                        'leaseExpiresAt' => null,
                        'cancelRequested' => true,
                    ]);
                    $this->moveAtomically($path, $this->root . '/failed/' . $job->id() . '.json', $cancelled);
                    $this->indexRun($cancelled);
                    $this->emit($job->id(), RuntimeEvent::CANCELLED, $nowUtc, [
                        'reason' => $cancelled->error(),
                        'reclaimed' => true,
                    ]);
                    $count++;

                    continue;
                }
                if ($job->attempts() >= $job->maxAttempts()) {
                    $failed = $job->with([
                        'status' => RuntimeJob::STATUS_FAILED,
                        'updatedAt' => $nowUtc,
                        'error' => 'Lease expired; max attempts reached.',
                        'leaseOwner' => null,
                        'leaseExpiresAt' => null,
                    ]);
                    $this->moveAtomically($path, $this->root . '/failed/' . $job->id() . '.json', $failed);
                    $this->indexRun($failed);
                    $this->emit($job->id(), RuntimeEvent::FAILED, $nowUtc, [
                        'error' => $failed->error(),
                        'reclaimed' => true,
                    ]);
                    $count++;

                    continue;
                }
                $queued = $job->with([
                    'status' => RuntimeJob::STATUS_QUEUED,
                    'updatedAt' => $nowUtc,
                    'leaseOwner' => null,
                    'leaseExpiresAt' => null,
                    'lastHeartbeatAt' => null,
                    'result' => null,
                    'error' => 'Lease expired; requeued.',
                    'cancelRequested' => false,
                    'cancelReason' => null,
                    'executed' => false,
                ]);
                $this->moveAtomically($path, $this->root . '/queued/' . $job->id() . '.json', $queued);
                $this->indexRun($queued);
                $this->emit($job->id(), RuntimeEvent::RETRY_SCHEDULED, $nowUtc, [
                    'reason' => 'lease_expired',
                    'attempts' => $queued->attempts(),
                    'maxAttempts' => $queued->maxAttempts(),
                ]);
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

    /** @return list<string> paths sorted by priority DESC, createdAt ASC, id ASC */
    private function listQueuedSorted(): array
    {
        $queuedDir = $this->root . '/queued';
        $files = glob($queuedDir . '/*.json') ?: [];
        $rows = [];
        foreach ($files as $path) {
            $job = $this->readJob($path);
            if ($job === null) {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'rank' => JobPriority::rank($job->priority()),
                'createdAt' => $job->createdAt(),
                'id' => $job->id(),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $b['rank'] <=> $a['rank']; // priority DESC
            }
            $created = strcmp((string) $a['createdAt'], (string) $b['createdAt']); // ASC
            if ($created !== 0) {
                return $created;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        return array_map(static fn (array $r): string => (string) $r['path'], $rows);
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
            'updatedAt' => $job->updatedAt(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @param array<string, mixed> $data */
    private function emit(string $jobId, string $type, string $at, array $data = []): void
    {
        $this->events->append(RuntimeEvent::create($jobId, $type, $at, $data));
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
}
