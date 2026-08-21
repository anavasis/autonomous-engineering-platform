<?php

declare(strict_types=1);

namespace Tests\Application\ExecutionRuntime;

use Aep\Application\Execution\ExecutionService;
use Aep\Application\ExecutionRuntime\Handler\MissionExecutionJobHandler;
use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Model\RuntimeEvent;
use Aep\Application\ExecutionRuntime\Service\JobDispatcher;
use Aep\Application\ExecutionRuntime\Service\RuntimeWorker;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\MissionExecution\Service\LaunchFacade;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSettingsStore;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\ExecutionRuntime\FilesystemJobQueue;
use Aep\Infrastructure\ExecutionRuntime\FilesystemRuntimeEventStore;
use Aep\Infrastructure\MissionEngine\InMemoryMissionRunRepository;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

final class MissionExecutionRuntimeTest
{
    public function test_launch_enqueues_and_worker_runs_mission_engine(): void
    {
        $root = sys_get_temp_dir() . '/aep_rt_me_' . bin2hex(random_bytes(4));
        try {
            [$launch, $worker, $queue, $events] = $this->build($root, false);
            $result = $launch->launch($this->actor(), $this->readyIntake(), $this->plan('in_1'));
            Assert::same('planned', $result['engineState']);
            Assert::true(isset($result['jobId']));
            $job = $queue->get((string) $result['jobId']);
            Assert::same('queued', $job?->status());
            Assert::same(JobPriority::NORMAL, $job?->priority());
            Assert::same('github', $job?->metadata()['provider'] ?? null);
            Assert::same('org/repo', $job?->metadata()['repository'] ?? null);
            Assert::same('user_rt', $job?->metadata()['requestedBy'] ?? null);
            Assert::same('codex', $job?->payload()['attributes']['providerId'] ?? null);

            Assert::same(1, $worker->processAvailable('test-worker', 1));
            $job = $queue->get((string) $result['jobId']);
            Assert::same('completed', $job?->status());
            Assert::same(MissionRunState::WAITING, $job?->result()['engineState'] ?? null);

            $types = array_map(
                static fn (RuntimeEvent $e) => $e->type(),
                $events->forJob((string) $result['jobId'])
            );
            Assert::true(in_array(RuntimeEvent::JOB_QUEUED, $types, true));
            Assert::true(in_array(RuntimeEvent::JOB_CLAIMED, $types, true));
            Assert::true(in_array(RuntimeEvent::COMPLETED, $types, true));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_inline_dispatcher_processes_before_return(): void
    {
        $root = sys_get_temp_dir() . '/aep_rt_inline_' . bin2hex(random_bytes(4));
        try {
            [$launch, , $queue] = $this->build($root, true);
            $result = $launch->launch($this->actor(), $this->readyIntake('in_2'), $this->plan('in_2'));
            Assert::true(isset($result['jobId']));
            Assert::same('completed', $queue->get((string) $result['jobId'])?->status());
            Assert::same(MissionRunState::WAITING, $queue->get((string) $result['jobId'])?->result()['engineState'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_cancel_prevents_queued_job_execution(): void
    {
        $root = sys_get_temp_dir() . '/aep_rt_cancel_' . bin2hex(random_bytes(4));
        try {
            [$launch, $worker, $queue, $events] = $this->build($root, false);
            $result = $launch->launch($this->actor(), $this->readyIntake('in_3'), $this->plan('in_3'));
            $jobId = (string) $result['jobId'];
            $queue->requestCancel($jobId, 'stop-now', Utc::now());
            Assert::same(0, $worker->processAvailable('w-cancel', 5));
            Assert::same('cancelled', $queue->get($jobId)?->status());
            $types = array_map(static fn (RuntimeEvent $e) => $e->type(), $events->forJob($jobId));
            Assert::true(in_array(RuntimeEvent::CANCELLED, $types, true));
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @return array{0: LaunchFacade, 1: RuntimeWorker, 2: FilesystemJobQueue, 3: FilesystemRuntimeEventStore}
     */
    private function build(string $root, bool $inline): array
    {
        if (!is_dir($root . '/runtime')) {
            mkdir($root . '/runtime', 0775, true);
        }
        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            new InMemoryMissionRunRepository(),
            new DefaultMissionPlanFactory(),
            new ExecutionService(new DeclarativeLocalExecutor()),
            new ValidationPipeline([new DeclarativeContextValidationStep()]),
        );
        $events = new FilesystemRuntimeEventStore($root . '/runtime');
        $queue = new FilesystemJobQueue($root . '/runtime', $events);
        $worker = new RuntimeWorker($queue, [new MissionExecutionJobHandler($engine)], 30);
        $dispatcher = new JobDispatcher($queue, $worker, $inline);
        $settings = new JsonExecutionSettingsStore($root . '/execution');
        $settings->update(['defaultProviderId' => 'codex']);
        $launch = new LaunchFacade($missions, $engine, $dispatcher, $settings);

        return [$launch, $worker, $queue, $events];
    }

    private function actor(): User
    {
        return new User(
            'user_rt',
            'operator',
            'Operator',
            password_hash('x', PASSWORD_ARGON2ID),
            new Role(Role::ADMIN),
            '2026-08-02T22:00:00Z',
        );
    }

    private function readyIntake(string $id = 'in_1'): MissionIntake
    {
        $at = Utc::now();
        $intake = new MissionIntake(
            $id,
            'user_rt',
            'Fix bug',
            MissionIntake::STATUS_RECEIVED,
            'conv_' . $id,
            $at,
            $at,
        );
        $intake->markReady($at);

        return $intake;
    }

    private function plan(string $intakeId): ExecutionPlan
    {
        return new ExecutionPlan(
            'plan_' . $intakeId,
            $intakeId,
            'aep.default_mission',
            '1',
            'Fix bug',
            null,
            'github',
            'org/repo',
            ['allowedPaths' => ['src/'], 'nonGoals' => [], 'branch' => 'main'],
            [],
            [],
            [],
            [],
            [['id' => 's1', 'name' => 'step']],
            60,
            ['whyWorkflow' => 'test'],
            [],
            ['objective' => 'Fix bug'],
            [],
            Utc::now(),
        );
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
