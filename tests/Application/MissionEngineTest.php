<?php

declare(strict_types=1);

namespace Tests\Application;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\RetryPolicy;
use Aep\Application\MissionEngine\TimeoutPolicy;
use Aep\Application\Validation\ValidationOutcome;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Application\Validation\ValidationRequest;
use Aep\Application\Validation\ValidationStep;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\MissionEngine\InMemoryMissionRunRepository;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

/**
 * ORCH-R9 MissionEngine application tests.
 */
final class MissionEngineTest
{
    public function test_happy_path(): void
    {
        $harness = $this->newHarness();
        $this->createDraft($harness, 'msn_engine_happy');

        $start = $harness['engine']->start($this->request(
            'run_happy',
            'msn_engine_happy',
            [
                'gate.inspection' => 'approved',
                'gate.commit' => 'approved',
                'allowedPaths' => ['src/Application/MissionEngine/'],
            ]
        ));

        Assert::same(MissionRunState::COMPLETED, $start->engineState()->toString());
        Assert::same(MissionState::COMPLETED, $start->missionState());
        Assert::same(100, $start->progressPercent());
        Assert::true(count($start->completedStepIds()) >= 12);
    }

    public function test_retry(): void
    {
        $fails = 2;
        $executor = new class ($fails) implements Executor {
            public function __construct(private int $remainingFailures)
            {
            }

            public function id(): string
            {
                return 'flaky';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                if ($this->remainingFailures > 0) {
                    $this->remainingFailures--;

                    return ExecutionResult::failed($this->id(), 'transient failure');
                }

                return ExecutionResult::succeeded($this->id(), 'recovered');
            }
        };

        $harness = $this->newHarness($executor, null, new RetryPolicy(3, 0));
        $this->createDraft($harness, 'msn_engine_retry');

        $result = $harness['engine']->start($this->request(
            'run_retry',
            'msn_engine_retry',
            [
                'gate.inspection' => 'approved',
                'gate.commit' => 'approved',
                'retryMaxAttempts' => 3,
            ],
            new RetryPolicy(3, 0)
        ));

        Assert::same(MissionRunState::COMPLETED, $result->engineState()->toString());
        Assert::same(MissionState::COMPLETED, $result->missionState());

        $timeline = $harness['runs']->getTimeline('run_retry');
        $events = array_map(static fn ($e) => $e->event(), $timeline->entries());
        Assert::contains('step_retry', $events);
    }

    public function test_timeout(): void
    {
        $executor = new class implements Executor {
            public function id(): string
            {
                return 'slow';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                usleep(30_000);

                return ExecutionResult::succeeded($this->id(), 'slow ok');
            }
        };

        $harness = $this->newHarness($executor);
        $this->createDraft($harness, 'msn_engine_timeout');

        $result = $harness['engine']->start($this->request(
            'run_timeout',
            'msn_engine_timeout',
            [
                'gate.inspection' => 'approved',
                'gate.commit' => 'approved',
            ],
            new RetryPolicy(1, 0),
            new TimeoutPolicy(0.01)
        ));

        Assert::same(MissionRunState::TIMED_OUT, $result->engineState()->toString());
        Assert::same('execute_implementation', $result->failedStepId());
    }

    public function test_cancel_and_resume(): void
    {
        $harness = $this->newHarness();
        $this->createDraft($harness, 'msn_engine_cancel');

        // Stop at first manual gate (no approval yet).
        $waiting = $harness['engine']->start($this->request(
            'run_cancel',
            'msn_engine_cancel',
            ['allowedPaths' => ['src/']]
        ));
        Assert::same(MissionRunState::WAITING, $waiting->engineState()->toString());
        Assert::same('inspection', $waiting->currentStepId());

        $cancelled = $harness['engine']->cancel('run_cancel', 'stop please', '2026-07-30T12:01:00Z');
        Assert::same(MissionRunState::SUSPENDED, $cancelled->engineState()->toString());

        $resumed = $harness['engine']->resume('run_cancel', [
            'gate.inspection' => 'approved',
            'gate.commit' => 'approved',
        ], '2026-07-30T12:02:00Z');

        Assert::same(MissionRunState::COMPLETED, $resumed->engineState()->toString());
        Assert::same(MissionState::COMPLETED, $resumed->missionState());
    }

    public function test_resume_from_waiting_gate(): void
    {
        $harness = $this->newHarness();
        $this->createDraft($harness, 'msn_engine_resume');

        $waiting = $harness['engine']->start($this->request(
            'run_resume',
            'msn_engine_resume',
            []
        ));
        Assert::same(MissionRunState::WAITING, $waiting->engineState()->toString());
        Assert::true($waiting->progressPercent() < 100);

        $afterInspection = $harness['engine']->resume('run_resume', [
            'gate.inspection' => 'approved',
        ], '2026-07-30T12:03:00Z');
        Assert::same(MissionRunState::WAITING, $afterInspection->engineState()->toString());
        Assert::same('commit', $afterInspection->currentStepId());

        $done = $harness['engine']->resume('run_resume', [
            'gate.commit' => 'approved',
        ], '2026-07-30T12:04:00Z');
        Assert::same(MissionRunState::COMPLETED, $done->engineState()->toString());
        Assert::same(100, $done->progressPercent());
    }

    public function test_validation_failure(): void
    {
        $failStep = new class implements ValidationStep {
            public function id(): string
            {
                return 'always_fail';
            }

            public function run(ValidationRequest $request): ValidationOutcome
            {
                return ValidationOutcome::failed($this->id(), 'validation exploded');
            }
        };

        $harness = $this->newHarness(null, new ValidationPipeline([$failStep]));
        $this->createDraft($harness, 'msn_engine_valfail');

        $result = $harness['engine']->start($this->request(
            'run_valfail',
            'msn_engine_valfail',
            [
                'gate.inspection' => 'approved',
                'gate.commit' => 'approved',
            ]
        ));

        Assert::same(MissionRunState::FAILED, $result->engineState()->toString());
        Assert::same('run_validation', $result->failedStepId());
        Assert::same(MissionState::CORRECTION_LOOP, $result->missionState());
    }

    public function test_progress(): void
    {
        $harness = $this->newHarness();
        $this->createDraft($harness, 'msn_engine_progress');

        $waiting = $harness['engine']->start($this->request(
            'run_progress',
            'msn_engine_progress',
            []
        ));
        Assert::same(MissionRunState::WAITING, $waiting->engineState()->toString());
        Assert::true($waiting->progressPercent() > 0);
        Assert::true($waiting->progressPercent() < 100);

        $mid = $waiting->progressPercent();
        $done = $harness['engine']->resume('run_progress', [
            'gate.inspection' => 'approved',
            'gate.commit' => 'approved',
        ], '2026-07-30T12:05:00Z');

        Assert::same(100, $done->progressPercent());
        Assert::true($done->progressPercent() >= $mid);
    }

    /**
     * @return array{
     *   engine: MissionEngine,
     *   missions: MissionCommandService,
     *   runs: InMemoryMissionRunRepository,
     *   missionRepo: InMemoryMissionRepository
     * }
     */
    private function newHarness(
        ?Executor $executor = null,
        ?ValidationPipeline $validation = null,
        ?RetryPolicy $retry = null
    ): array {
        unset($retry);
        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $runs = new InMemoryMissionRunRepository();
        $execution = new ExecutionService($executor ?? new DeclarativeLocalExecutor());
        $validation ??= new ValidationPipeline([new DeclarativeContextValidationStep()]);

        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            $runs,
            new DefaultMissionPlanFactory(),
            $execution,
            $validation
        );

        return [
            'engine' => $engine,
            'missions' => $missions,
            'runs' => $runs,
            'missionRepo' => $missionRepo,
        ];
    }

    /**
     * @param array<string, mixed> $harness
     */
    private function createDraft(array $harness, string $missionId): void
    {
        /** @var MissionCommandService $missions */
        $missions = $harness['missions'];
        $missions->create(new CreateMission(
            $missionId,
            'github',
            'anavasis/example-target',
            'ORCH-R9 mission engine verification',
            'user',
            'tester-1',
            '2026-07-30T12:00:00Z'
        ));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(
        string $runId,
        string $missionId,
        array $attributes = [],
        ?RetryPolicy $retry = null,
        ?TimeoutPolicy $timeout = null
    ): MissionEngineRequest {
        return new MissionEngineRequest(
            $runId,
            $missionId,
            '2026-07-30T12:00:00Z',
            'user',
            'tester-1',
            $attributes,
            'proj_engine',
            $retry ?? new RetryPolicy(3, 0),
            $timeout ?? TimeoutPolicy::disabled()
        );
    }
}
