<?php

declare(strict_types=1);

namespace Tests\Infrastructure\ExecutionRuntime;

use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Model\RuntimeEvent;
use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Infrastructure\ExecutionRuntime\FilesystemJobQueue;
use Aep\Infrastructure\ExecutionRuntime\FilesystemRuntimeEventStore;
use Tests\Support\Assert;

final class FilesystemJobQueueTest
{
    public function test_enqueue_claim_complete_and_run_index(): void
    {
        $root = sys_get_temp_dir() . '/aep_rtq_' . bin2hex(random_bytes(4));
        $events = new FilesystemRuntimeEventStore($root);
        $queue = new FilesystemJobQueue($root, $events);
        $now = '2026-08-02T22:00:00Z';
        $job = new RuntimeJob(
            'job_a',
            'mission-execution',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::NORMAL,
            ['action' => 'start', 'runId' => 'run_a', 'missionId' => 'msn_a'],
            ['provider' => 'github', 'repository' => 'org/repo', 'requestedBy' => 'u1'],
            0,
            3,
            $now,
            $now,
        );
        $queue->enqueue($job);

        $claimed = $queue->claim('w1', 30, $now);
        Assert::true($claimed !== null);
        Assert::same('job_a', $claimed->id());
        Assert::same(RuntimeJob::STATUS_RUNNING, $claimed->status());
        Assert::same(1, $claimed->attempts());
        Assert::same('w1', $claimed->leaseOwner());
        Assert::same(JobPriority::NORMAL, $claimed->priority());
        Assert::same('github', $claimed->metadata()['provider'] ?? null);

        Assert::true($queue->heartbeat('job_a', 'w1', 30, '2026-08-02T22:00:10Z'));
        $queue->complete('job_a', 'w1', ['engineState' => 'waiting'], '2026-08-02T22:00:20Z');

        $done = $queue->get('job_a');
        Assert::same(RuntimeJob::STATUS_COMPLETED, $done?->status());
        Assert::true($done?->executed() === true);
        Assert::same('job_a', $queue->findByRunId('run_a')?->id());

        $types = array_map(static fn (RuntimeEvent $e) => $e->type(), $events->forJob('job_a'));
        Assert::true(in_array(RuntimeEvent::JOB_QUEUED, $types, true));
        Assert::true(in_array(RuntimeEvent::JOB_CLAIMED, $types, true));
        Assert::true(in_array(RuntimeEvent::HEARTBEAT, $types, true));
        Assert::true(in_array(RuntimeEvent::LEASE_RENEWED, $types, true));
        Assert::true(in_array(RuntimeEvent::COMPLETED, $types, true));

        Assert::same(null, $queue->claim('w2', 30, '2026-08-02T22:01:00Z'));
        $this->removeDir($root);
    }

    public function test_claim_order_priority_desc_then_created_at_asc(): void
    {
        $root = sys_get_temp_dir() . '/aep_rtq_prio_' . bin2hex(random_bytes(4));
        $queue = new FilesystemJobQueue($root);
        $queue->enqueue(new RuntimeJob(
            'job_low',
            'demo',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::LOW,
            ['runId' => 'r_low'],
            [],
            0,
            3,
            '2026-08-02T22:00:00Z',
            '2026-08-02T22:00:00Z',
        ));
        $queue->enqueue(new RuntimeJob(
            'job_crit_late',
            'demo',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::CRITICAL,
            ['runId' => 'r_crit_late'],
            [],
            0,
            3,
            '2026-08-02T22:00:02Z',
            '2026-08-02T22:00:02Z',
        ));
        $queue->enqueue(new RuntimeJob(
            'job_crit_early',
            'demo',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::CRITICAL,
            ['runId' => 'r_crit_early'],
            [],
            0,
            3,
            '2026-08-02T22:00:01Z',
            '2026-08-02T22:00:01Z',
        ));
        $queue->enqueue(new RuntimeJob(
            'job_high',
            'demo',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::HIGH,
            ['runId' => 'r_high'],
            [],
            0,
            3,
            '2026-08-02T21:59:00Z',
            '2026-08-02T21:59:00Z',
        ));

        Assert::same('job_crit_early', $queue->claim('w1', 30, '2026-08-02T22:01:00Z')?->id());
        Assert::same('job_crit_late', $queue->claim('w1', 30, '2026-08-02T22:01:01Z')?->id());
        Assert::same('job_high', $queue->claim('w1', 30, '2026-08-02T22:01:02Z')?->id());
        Assert::same('job_low', $queue->claim('w1', 30, '2026-08-02T22:01:03Z')?->id());
        $this->removeDir($root);
    }

    public function test_lease_reclaim_does_not_duplicate_completed_work(): void
    {
        $root = sys_get_temp_dir() . '/aep_rtq_' . bin2hex(random_bytes(4));
        $events = new FilesystemRuntimeEventStore($root);
        $queue = new FilesystemJobQueue($root, $events);
        $now = '2026-08-02T22:00:00Z';
        $queue->enqueue(new RuntimeJob(
            'job_b',
            'demo',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::NORMAL,
            ['runId' => 'run_b'],
            ['requestedBy' => 'sys'],
            0,
            2,
            $now,
            $now,
        ));
        $claimed = $queue->claim('w1', 1, $now);
        Assert::true($claimed !== null);

        $reclaimed = $queue->reclaimExpiredLeases('2026-08-02T22:00:05Z');
        Assert::true($reclaimed >= 1);
        $types = array_map(static fn (RuntimeEvent $e) => $e->type(), $events->forJob('job_b'));
        Assert::true(in_array(RuntimeEvent::RETRY_SCHEDULED, $types, true));

        $again = $queue->claim('w2', 30, '2026-08-02T22:00:06Z');
        Assert::true($again !== null);
        Assert::same('job_b', $again->id());
        Assert::same(2, $again->attempts());

        $queue->complete('job_b', 'w2', ['ok' => true], '2026-08-02T22:00:07Z');
        Assert::same(0, $queue->reclaimExpiredLeases('2026-08-02T23:00:00Z'));
        Assert::same(RuntimeJob::STATUS_COMPLETED, $queue->get('job_b')?->status());
        $this->removeDir($root);
    }

    public function test_cancel_queued_job_never_executes(): void
    {
        $root = sys_get_temp_dir() . '/aep_rtq_' . bin2hex(random_bytes(4));
        $events = new FilesystemRuntimeEventStore($root);
        $queue = new FilesystemJobQueue($root, $events);
        $now = '2026-08-02T22:00:00Z';
        $queue->enqueue(new RuntimeJob(
            'job_c',
            'demo',
            RuntimeJob::STATUS_QUEUED,
            JobPriority::NORMAL,
            ['runId' => 'run_c'],
            [],
            0,
            3,
            $now,
            $now,
        ));
        $queue->requestCancel('job_c', 'stop', $now);
        Assert::same(RuntimeJob::STATUS_CANCELLED, $queue->get('job_c')?->status());
        Assert::same(null, $queue->claim('w1', 30, $now));
        $types = array_map(static fn (RuntimeEvent $e) => $e->type(), $events->forJob('job_c'));
        Assert::true(in_array(RuntimeEvent::CANCELLED, $types, true));
        $this->removeDir($root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
