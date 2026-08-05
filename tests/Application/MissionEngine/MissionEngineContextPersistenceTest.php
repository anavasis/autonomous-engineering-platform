<?php

declare(strict_types=1);

namespace Tests\Application\MissionEngine;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionEngine\RetryPolicy;
use Aep\Application\MissionEngine\TimeoutPolicy;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\MissionEngine\InMemoryMissionRunRepository;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

final class MissionEngineContextPersistenceTest
{
    public function test_successful_step_result_context_is_persisted_and_protected_keys_remain(): void
    {
        $runs = new InMemoryMissionRunRepository();
        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $missions->create(new CreateMission(
            'msn_persist_1',
            'github',
            'anavasis/aep-codex-smoke',
            'obj',
            'user',
            'tester',
            '2026-08-05T12:00:00Z'
        ));
        $missions->defineScope(new DefineScope('msn_persist_1', ['README.md'], []));

        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            $runs,
            new DefaultMissionPlanFactory(),
            new ExecutionService($this->evidenceExecutor()),
            new ValidationPipeline([new DeclarativeContextValidationStep()]),
        );

        $waiting = $engine->start(new MissionEngineRequest(
            'run_persist_1',
            'msn_persist_1',
            '2026-08-05T12:00:00Z',
            'user',
            'tester',
            [
                'providerId' => 'codex',
                'allowedPaths' => ['README.md'],
                'workflowId' => 'aep.default_mission',
                'planId' => 'plan_keep',
                'confirmedBy' => 'tester',
                'intakeId' => 'in_keep',
            ],
            null,
            new RetryPolicy(1, 0),
            TimeoutPolicy::disabled()
        ));
        Assert::same(MissionRunState::WAITING, $waiting->engineState()->toString());

        $afterInspection = $engine->resume('run_persist_1', [
            'gate.inspection' => 'approved',
        ], '2026-08-05T12:01:00Z');
        Assert::same(MissionRunState::WAITING, $afterInspection->engineState()->toString());
        Assert::same('commit', $afterInspection->currentStepId());

        $attrs = $runs->getCheckpoint('run_persist_1')->attributes();
        Assert::same('codex', $attrs['providerId'] ?? null);
        Assert::same('esess_persist', $attrs['sessionId'] ?? null);
        Assert::same('/tmp/ws_persist', $attrs['workspacePath'] ?? null);
        Assert::same(['README.md'], $attrs['filesChanged'] ?? null);
        Assert::same('patch_persist', $attrs['patchId'] ?? null);
        Assert::same('passed', $attrs['validationOutcome'] ?? null);
        Assert::same('aep.default_mission', $attrs['workflowId'] ?? null);
        Assert::same('plan_keep', $attrs['planId'] ?? null);
        Assert::same('tester', $attrs['confirmedBy'] ?? null);
        Assert::same('in_keep', $attrs['intakeId'] ?? null);
        Assert::same(['README.md'], $attrs['allowedPaths'] ?? null);
        Assert::same('approved', $attrs['gate.inspection'] ?? null);
    }

    public function test_resume_sees_persisted_evidence_for_completion(): void
    {
        $runs = new InMemoryMissionRunRepository();
        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $missions->create(new CreateMission(
            'msn_persist_2',
            'github',
            'anavasis/aep-codex-smoke',
            'obj',
            'user',
            'tester',
            '2026-08-05T12:00:00Z'
        ));
        $missions->defineScope(new DefineScope('msn_persist_2', ['README.md'], []));

        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            $runs,
            new DefaultMissionPlanFactory(),
            new ExecutionService($this->evidenceExecutor()),
            new ValidationPipeline([new DeclarativeContextValidationStep()]),
        );

        $engine->start(new MissionEngineRequest(
            'run_persist_2',
            'msn_persist_2',
            '2026-08-05T12:00:00Z',
            'user',
            'tester',
            [
                'providerId' => 'codex',
                'allowedPaths' => ['README.md'],
                'gate.inspection' => 'approved',
            ],
            null,
            new RetryPolicy(1, 0),
            TimeoutPolicy::disabled()
        ));

        $done = $engine->resume('run_persist_2', [
            'gate.commit' => 'approved',
        ], '2026-08-05T12:02:00Z');

        Assert::same(MissionRunState::COMPLETED, $done->engineState()->toString());
        Assert::same(MissionState::COMPLETED, $done->missionState());
        $msgs = [];
        foreach ($runs->getTimeline('run_persist_2')->entries() as $entry) {
            if ($entry->step() === 'complete_mission' && $entry->event() === 'step_finished') {
                $msgs[] = $entry->message();
            }
        }
        Assert::true(in_array('Mission completed with verified patch artifact.', $msgs, true));
        Assert::true(!str_contains(strtolower(implode(' ', $msgs)), 'pr created'));
        Assert::true(!str_contains(strtolower(implode(' ', $msgs)), 'declarative local'));
    }

    private function evidenceExecutor(): Executor
    {
        return new class implements Executor {
            public function id(): string
            {
                return 'provider_routing';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                return ExecutionResult::succeeded($this->id(), 'provider ok', [
                    'providerId' => 'codex',
                    'routedProviderId' => 'codex',
                    'actualExecutorId' => 'provider_routing',
                    'sessionId' => 'esess_persist',
                    'workspacePath' => '/tmp/ws_persist',
                    'filesChanged' => ['README.md'],
                    'artifacts' => ['diff' => 'art_1'],
                    'usage' => ['durationSeconds' => 2.0],
                    'checkpointId' => 'cp_persist',
                    'patchId' => 'patch_persist',
                    'patchStatus' => 'ready',
                    'mergeReady' => true,
                ]);
            }
        };
    }
}
