<?php

declare(strict_types=1);

namespace Tests\Application\MissionEngine;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\Command\SubmitInspection;
use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\CancellationToken;
use Aep\Application\MissionEngine\DefaultMissionPlanFactory;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionEngine\RetryPolicy;
use Aep\Application\MissionEngine\Step\ApproveInspectionStep;
use Aep\Application\MissionEngine\TimeoutPolicy;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\MissionEngine\InMemoryMissionRunRepository;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Aep\Infrastructure\Validation\DeclarativeContextValidationStep;
use Tests\Support\Assert;

final class ApproveInspectionStepIdempotencyTest
{
    public function test_awaiting_inspection_approval_transitions_to_implementing(): void
    {
        $svc = $this->missions();
        $this->toAwaiting($svc, 'msn_ai_1');
        $step = new ApproveInspectionStep();
        $result = $step->execute($this->context($svc, 'msn_ai_1'));
        Assert::true($result->isSucceeded());
        Assert::same(
            MissionState::IMPLEMENTING,
            $svcLoad = $this->load($svc, 'msn_ai_1')->state()->toString()
        );
        Assert::true($this->load($svc, 'msn_ai_1')->inspectionApproval()?->isApproved() === true);
    }

    public function test_already_implementing_with_approved_inspection_succeeds_idempotently(): void
    {
        $svc = $this->missions();
        $this->toAwaiting($svc, 'msn_ai_2');
        $svc->approveInspection(new ApprovalCommand('msn_ai_2', 'user', 'tester', '2026-08-04T10:00:00Z', 'ui'));
        Assert::same(MissionState::IMPLEMENTING, $this->load($svc, 'msn_ai_2')->state()->toString());
        $before = $this->load($svc, 'msn_ai_2')->inspectionApproval();
        Assert::true($before !== null);

        $step = new ApproveInspectionStep();
        $result = $step->execute($this->context($svc, 'msn_ai_2'));
        Assert::true($result->isSucceeded());
        Assert::same('Inspection already approved.', $result->message());
        Assert::same(MissionState::IMPLEMENTING, $this->load($svc, 'msn_ai_2')->state()->toString());
        $after = $this->load($svc, 'msn_ai_2')->inspectionApproval();
        Assert::true($after !== null);
        Assert::same($before?->rationale(), $after?->rationale());
        Assert::same($before?->occurredAtUtc(), $after?->occurredAtUtc());
        Assert::same($before?->actor()->id(), $after?->actor()->id());
    }

    public function test_implementing_without_approved_inspection_fails(): void
    {
        $svc = $this->missions();
        // Craft implementing without going through approveInspection by using fixture reconstitution
        // is not available via command service. Use approve then clear via repo reconstitute.
        $repo = new InMemoryMissionRepository();
        $svc = new MissionCommandService($repo);
        $this->toAwaiting($svc, 'msn_ai_3');
        $svc->approveInspection(new ApprovalCommand('msn_ai_3', 'user', 'tester', '2026-08-04T10:00:00Z'));
        $mission = $repo->get(new MissionId('msn_ai_3'));
        // Reconstitute implementing without approval record.
        $stripped = \Aep\Domain\Mission\Mission::reconstitute(
            $mission->id(),
            $mission->target(),
            $mission->brief(),
            $mission->createdBy(),
            $mission->createdAtUtc(),
            new \Aep\Domain\Mission\ValueObject\MissionState(MissionState::IMPLEMENTING),
            $mission->scopePolicy(),
            $mission->inspectionFindings(),
            null,
            null,
            null
        );
        $repo->save($stripped);

        $step = new ApproveInspectionStep();
        $result = $step->execute($this->context($svc, 'msn_ai_3'));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'without an approved inspection record'));
    }

    public function test_rejected_inspection_path_fails_when_awaiting_after_reject_then_wrong_state(): void
    {
        $svc = $this->missions();
        $this->toAwaiting($svc, 'msn_ai_4');
        $svc->rejectInspection(new ApprovalCommand('msn_ai_4', 'user', 'tester', '2026-08-04T10:00:00Z', 'no'));
        Assert::same(MissionState::INSPECTING, $this->load($svc, 'msn_ai_4')->state()->toString());

        $step = new ApproveInspectionStep();
        $result = $step->execute($this->context($svc, 'msn_ai_4'));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'Cannot approve inspection from mission state inspecting'));
    }

    public function test_unrelated_invalid_state_fails(): void
    {
        $svc = $this->missions();
        $svc->create(new CreateMission(
            'msn_ai_5',
            'github',
            'owner/repo',
            'obj',
            'user',
            'tester',
            '2026-08-04T10:00:00Z'
        ));
        $step = new ApproveInspectionStep();
        $result = $step->execute($this->context($svc, 'msn_ai_5'));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'draft'));
    }

    public function test_engine_canonical_gate_resume_advances_to_execute_implementation(): void
    {
        $harness = $this->engineHarness();
        $this->createDraft($harness, 'msn_e2e_1');

        $waiting = $harness['engine']->start($this->engineRequest('run_e2e_1', 'msn_e2e_1'));
        Assert::same(MissionRunState::WAITING, $waiting->engineState()->toString());
        Assert::same('inspection', $waiting->currentStepId());
        Assert::same(MissionState::AWAITING_INSPECTION_APPROVAL, $waiting->missionState());

        $after = $harness['engine']->resume('run_e2e_1', [
            'gate.inspection' => 'approved',
            // Stop before commit gate / keep fake executor; fail at validation not needed —
            // use forceFail via not approving commit: wait at commit after implementation.
        ], '2026-08-04T10:10:00Z');

        Assert::same(MissionRunState::WAITING, $after->engineState()->toString());
        Assert::same('commit', $after->currentStepId());
        Assert::same(MissionState::AWAITING_COMMIT_APPROVAL, $after->missionState());
        Assert::true($this->load($harness['missions'], 'msn_e2e_1')->inspectionApproval()?->isApproved() === true);
    }

    public function test_engine_prior_decide_inspection_then_gate_resume_is_idempotent(): void
    {
        $harness = $this->engineHarness();
        $this->createDraft($harness, 'msn_e2e_2');

        $waiting = $harness['engine']->start($this->engineRequest('run_e2e_2', 'msn_e2e_2'));
        Assert::same(MissionRunState::WAITING, $waiting->engineState()->toString());

        // Simulate Approvals kind=inspection footgun: domain transition before gate resume.
        $harness['missions']->approveInspection(new ApprovalCommand(
            'msn_e2e_2',
            'user',
            'tester',
            '2026-08-04T10:05:00Z',
            'Approved from Mission Control'
        ));
        Assert::same(MissionState::IMPLEMENTING, $this->load($harness['missions'], 'msn_e2e_2')->state()->toString());
        $approval = $this->load($harness['missions'], 'msn_e2e_2')->inspectionApproval();
        Assert::true($approval !== null);
        $rationale = $approval->rationale();

        $after = $harness['engine']->resume('run_e2e_2', [
            'gate.inspection' => 'approved',
        ], '2026-08-04T10:10:00Z');

        Assert::true(
            $after->engineState()->toString() !== MissionRunState::FAILED,
            'Resume failed: ' . $after->message()
        );
        Assert::same(MissionRunState::WAITING, $after->engineState()->toString());
        Assert::same('commit', $after->currentStepId());
        $afterApproval = $this->load($harness['missions'], 'msn_e2e_2')->inspectionApproval();
        Assert::same($rationale, $afterApproval?->rationale());
    }

    public function test_duplicate_approve_inspection_command_still_fails_closed_on_domain(): void
    {
        $svc = $this->missions();
        $this->toAwaiting($svc, 'msn_dup_cmd');
        $svc->approveInspection(new ApprovalCommand('msn_dup_cmd', 'user', 'tester', '2026-08-04T10:00:00Z'));
        try {
            $svc->approveInspection(new ApprovalCommand('msn_dup_cmd', 'user', 'tester', '2026-08-04T10:01:00Z'));
            Assert::true(false, 'Expected domain guard to reject duplicate approveInspection');
        } catch (\DomainException $e) {
            Assert::true(str_contains($e->getMessage(), 'Expected state awaiting_inspection_approval'));
        }
    }

    private function missions(): MissionCommandService
    {
        return new MissionCommandService(new InMemoryMissionRepository());
    }

    private function load(MissionCommandService $svc, string $missionId): \Aep\Domain\Mission\Mission
    {
        $load = \Closure::bind(
            function (string $id): \Aep\Domain\Mission\Mission {
                return $this->load($id);
            },
            $svc,
            MissionCommandService::class
        );
        Assert::true(is_callable($load));

        return $load($missionId);
    }

    private function toAwaiting(MissionCommandService $svc, string $missionId): void
    {
        $at = '2026-08-04T10:00:00Z';
        $svc->create(new CreateMission($missionId, 'github', 'owner/repo', 'obj', 'user', 'tester', $at));
        $svc->defineScope(new DefineScope($missionId, ['src/'], []));
        $svc->startInspection(new TimedMissionCommand($missionId, $at));
        $svc->submitInspection(new SubmitInspection($missionId, 'ready', $at));
    }

    private function context(MissionCommandService $svc, string $missionId): MissionContext
    {
        $mission = $this->load($svc, $missionId);

        return new MissionContext(
            'run_step',
            $missionId,
            '2026-08-04T10:00:00Z',
            'user',
            'tester',
            $svc,
            new CancellationToken(),
            [],
            null,
            null,
            null,
            $mission->state()->toString()
        );
    }

    /**
     * @return array{engine: MissionEngine, missions: MissionCommandService, runs: InMemoryMissionRunRepository}
     */
    private function engineHarness(): array
    {
        $missionRepo = new InMemoryMissionRepository();
        $missions = new MissionCommandService($missionRepo);
        $runs = new InMemoryMissionRunRepository();
        $executor = new class implements Executor {
            public function id(): string
            {
                return 'provider_routing';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                return ExecutionResult::succeeded($this->id(), 'ok', [
                    'providerId' => 'codex',
                    'routedProviderId' => 'codex',
                    'actualExecutorId' => 'provider_routing',
                    'sessionId' => 'esess_insp',
                    'workspacePath' => '/tmp/ws_insp',
                    'filesChanged' => ['src/Hello.php'],
                    'artifacts' => ['diff' => 'a'],
                    'usage' => [],
                    'checkpointId' => 'cp_insp',
                    'patchId' => 'patch_insp',
                    'patchStatus' => 'ready',
                    'mergeReady' => true,
                ]);
            }
        };
        $engine = new MissionEngine(
            $missions,
            $missionRepo,
            $runs,
            new DefaultMissionPlanFactory(),
            new ExecutionService($executor),
            new ValidationPipeline([new DeclarativeContextValidationStep()])
        );

        return ['engine' => $engine, 'missions' => $missions, 'runs' => $runs];
    }

    /** @param array{missions: MissionCommandService} $harness */
    private function createDraft(array $harness, string $missionId): void
    {
        $harness['missions']->create(new CreateMission(
            $missionId,
            'github',
            'owner/repo',
            'e2e',
            'user',
            'tester',
            '2026-08-04T10:00:00Z'
        ));
    }

    private function engineRequest(string $runId, string $missionId): MissionEngineRequest
    {
        return new MissionEngineRequest(
            $runId,
            $missionId,
            '2026-08-04T10:00:00Z',
            'user',
            'tester',
            [
                'providerId' => 'codex',
                'allowedPaths' => ['src/'],
            ],
            null,
            new RetryPolicy(1, 0),
            TimeoutPolicy::disabled()
        );
    }
}
