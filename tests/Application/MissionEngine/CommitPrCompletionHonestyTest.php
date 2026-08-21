<?php

declare(strict_types=1);

namespace Tests\Application\MissionEngine;

use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\Command\RecordValidation;
use Aep\Application\Mission\Command\SubmitInspection;
use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\CancellationToken;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\Step\ApproveCommitStep;
use Aep\Application\MissionEngine\Step\CompleteMissionStep;
use Aep\Application\MissionEngine\Step\MarkPrReadyStep;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Tests\Support\Assert;

final class CommitPrCompletionHonestyTest
{
    public function test_approve_commit_once_from_awaiting(): void
    {
        $svc = $this->missions();
        $this->toAwaitingCommit($svc, 'msn_ac_1');
        $result = (new ApproveCommitStep())->execute($this->context($svc, 'msn_ac_1'));
        Assert::true($result->isSucceeded());
        Assert::same(MissionState::COMMITTING, $this->load($svc, 'msn_ac_1')->state()->toString());
    }

    public function test_approve_commit_idempotent_when_already_committing(): void
    {
        $svc = $this->missions();
        $this->toAwaitingCommit($svc, 'msn_ac_2');
        $svc->approveCommit(new ApprovalCommand('msn_ac_2', 'user', 'tester', '2026-08-05T12:00:00Z', 'ui'));
        $before = $this->load($svc, 'msn_ac_2')->commitApproval();
        $result = (new ApproveCommitStep())->execute($this->context($svc, 'msn_ac_2'));
        Assert::true($result->isSucceeded());
        Assert::same('Commit already approved.', $result->message());
        Assert::same($before?->rationale(), $this->load($svc, 'msn_ac_2')->commitApproval()?->rationale());
    }

    public function test_approve_commit_contradictory_state_fails(): void
    {
        $svc = $this->missions();
        $svc->create(new CreateMission('msn_ac_3', 'github', 'o/r', 'o', 'user', 't', '2026-08-05T12:00:00Z'));
        $result = (new ApproveCommitStep())->execute($this->context($svc, 'msn_ac_3'));
        Assert::true($result->isFailed());
    }

    public function test_mark_pr_ready_requires_patch_evidence(): void
    {
        $svc = $this->missions();
        $this->toCommitting($svc, 'msn_pr_1');
        $result = (new MarkPrReadyStep())->execute($this->context($svc, 'msn_pr_1', []));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'patchId'));
    }

    public function test_mark_pr_ready_blocks_when_merge_ready_false(): void
    {
        $svc = $this->missions();
        $this->toCommitting($svc, 'msn_pr_2');
        $result = (new MarkPrReadyStep())->execute($this->context($svc, 'msn_pr_2', $this->evidence(['mergeReady' => false])));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'mergeReady'));
    }

    public function test_mark_pr_ready_honest_message(): void
    {
        $svc = $this->missions();
        $this->toCommitting($svc, 'msn_pr_3');
        $result = (new MarkPrReadyStep())->execute($this->context($svc, 'msn_pr_3', $this->evidence()));
        Assert::true($result->isSucceeded());
        Assert::same('Patch is ready for PR creation.', $result->message());
        Assert::true(!str_contains(strtolower($result->message()), 'pr marked ready'));
        Assert::true(!str_contains(strtolower($result->message()), 'pr created'));
    }

    public function test_complete_without_evidence_fails(): void
    {
        $svc = $this->missions();
        $this->toPrReady($svc, 'msn_cm_1');
        $result = (new CompleteMissionStep())->execute($this->context($svc, 'msn_cm_1', []));
        Assert::true($result->isFailed());
    }

    public function test_complete_with_verified_evidence_succeeds(): void
    {
        $svc = $this->missions();
        $this->toPrReady($svc, 'msn_cm_2');
        $result = (new CompleteMissionStep())->execute($this->context($svc, 'msn_cm_2', $this->evidence()));
        Assert::true($result->isSucceeded());
        Assert::same('Mission completed with verified patch artifact.', $result->message());
        Assert::same(MissionState::COMPLETED, $this->load($svc, 'msn_cm_2')->state()->toString());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'providerId' => 'codex',
            'sessionId' => 'esess_x',
            'workspacePath' => '/tmp/ws',
            'filesChanged' => ['README.md'],
            'patchId' => 'patch_x',
            'patchStatus' => 'ready',
            'mergeReady' => true,
            'validationOutcome' => 'passed',
        ], $overrides);
    }

    private function missions(): MissionCommandService
    {
        return new MissionCommandService(new InMemoryMissionRepository());
    }

    private function toAwaitingCommit(MissionCommandService $svc, string $id): void
    {
        $at = '2026-08-05T12:00:00Z';
        $svc->create(new CreateMission($id, 'github', 'o/r', 'o', 'user', 't', $at));
        $svc->defineScope(new DefineScope($id, ['README.md'], []));
        $svc->startInspection(new TimedMissionCommand($id, $at));
        $svc->submitInspection(new SubmitInspection($id, 'ready', $at));
        $svc->approveInspection(new ApprovalCommand($id, 'user', 't', $at));
        $svc->finishImplementation(new TimedMissionCommand($id, $at));
        $svc->recordValidation(new RecordValidation($id, 'passed', 'ok', $at));
    }

    private function toCommitting(MissionCommandService $svc, string $id): void
    {
        $this->toAwaitingCommit($svc, $id);
        $svc->approveCommit(new ApprovalCommand($id, 'user', 't', '2026-08-05T12:00:00Z'));
    }

    private function toPrReady(MissionCommandService $svc, string $id): void
    {
        $this->toCommitting($svc, $id);
        $svc->markPrReady(new TimedMissionCommand($id, '2026-08-05T12:00:00Z'));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function context(MissionCommandService $svc, string $missionId, array $attributes = []): MissionContext
    {
        return new MissionContext(
            'run_' . $missionId,
            $missionId,
            '2026-08-05T12:00:00Z',
            'user',
            'tester',
            $svc,
            new CancellationToken(),
            $attributes,
        );
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
}
