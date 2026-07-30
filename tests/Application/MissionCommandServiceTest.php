<?php

declare(strict_types=1);

namespace Tests\Application;

use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\Command\RecordValidation;
use Aep\Application\Mission\Command\SubmitInspection;
use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\Mission\MissionCommandService;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Tests\Support\Assert;
use Tests\Support\MissionFixtures;

/**
 * Application orchestration via InMemoryMissionRepository.
 */
final class MissionCommandServiceTest
{
    public function test_create_persists_and_returns_result(): void
    {
        $service = $this->newService();
        $result = $service->create($this->createCommand('msn_app_1'));
        Assert::same('msn_app_1', $result->missionId()->toString());
        Assert::same(MissionState::DRAFT, $result->state()->toString());
        Assert::eventNames(['MissionCreated'], $result->events());
    }

    public function test_every_public_method_happy_path(): void
    {
        $service = $this->newService();
        $id = 'msn_app_all';
        $at = MissionFixtures::AT;

        $r = $service->create($this->createCommand($id));
        Assert::same(MissionState::DRAFT, $r->state()->toString());

        $r = $service->defineScope(new DefineScope($id, ['src/Domain/Mission/Mission.php'], ['executors']));
        Assert::same(MissionState::DRAFT, $r->state()->toString());
        Assert::same([], $r->events());

        $r = $service->startInspection(new TimedMissionCommand($id, $at));
        Assert::same(MissionState::INSPECTING, $r->state()->toString());
        Assert::eventNames(['InspectionStarted'], $r->events());

        $r = $service->submitInspection(new SubmitInspection($id, 'ready for review', $at));
        Assert::same(MissionState::AWAITING_INSPECTION_APPROVAL, $r->state()->toString());
        Assert::eventNames(['InspectionSubmitted'], $r->events());

        $r = $service->approveInspection(new ApprovalCommand($id, 'user', 'tester-1', $at));
        Assert::same(MissionState::IMPLEMENTING, $r->state()->toString());
        Assert::contains('InspectionApprovalGranted', $this->names($r->events()));
        Assert::contains('ImplementationStarted', $this->names($r->events()));

        $r = $service->finishImplementation(new TimedMissionCommand($id, $at));
        Assert::same(MissionState::VALIDATING, $r->state()->toString());
        Assert::contains('ImplementationFinished', $this->names($r->events()));
        Assert::contains('ValidationStarted', $this->names($r->events()));

        $r = $service->recordValidation(new RecordValidation($id, 'passed', 'ok', $at));
        Assert::same(MissionState::AWAITING_COMMIT_APPROVAL, $r->state()->toString());
        Assert::eventNames(['ValidationPassed'], $r->events());

        $r = $service->approveCommit(new ApprovalCommand($id, 'user', 'tester-1', $at));
        Assert::same(MissionState::COMMITTING, $r->state()->toString());
        Assert::contains('CommitApprovalGranted', $this->names($r->events()));
        Assert::contains('CommitPhaseStarted', $this->names($r->events()));

        $r = $service->markPrReady(new TimedMissionCommand($id, $at));
        Assert::same(MissionState::PR_READY, $r->state()->toString());
        Assert::eventNames(['PullRequestMarkedReady'], $r->events());

        $r = $service->complete(new TimedMissionCommand($id, $at));
        Assert::same(MissionState::COMPLETED, $r->state()->toString());
        Assert::eventNames(['MissionCompleted'], $r->events());
    }

    public function test_reject_inspection_and_reject_commit_paths(): void
    {
        $service = $this->newService();
        $id = 'msn_app_reject';
        $at = MissionFixtures::AT;
        $service->create($this->createCommand($id));
        $service->defineScope(new DefineScope($id, ['src/Domain/Mission/Mission.php']));
        $service->startInspection(new TimedMissionCommand($id, $at));
        $service->submitInspection(new SubmitInspection($id, 'findings', $at));

        $r = $service->rejectInspection(new ApprovalCommand($id, 'user', 'tester-1', $at, 'revise'));
        Assert::same(MissionState::INSPECTING, $r->state()->toString());
        Assert::eventNames(['InspectionApprovalRejected'], $r->events());

        $service->submitInspection(new SubmitInspection($id, 'revised', $at));
        $service->approveInspection(new ApprovalCommand($id, 'user', 'tester-1', $at));
        $service->finishImplementation(new TimedMissionCommand($id, $at));
        $service->recordValidation(new RecordValidation($id, 'passed', 'ok', $at));

        $r = $service->rejectCommit(new ApprovalCommand($id, 'user', 'tester-1', $at, 'no'));
        Assert::same(MissionState::CORRECTION_LOOP, $r->state()->toString());
        Assert::contains('CommitApprovalRejected', $this->names($r->events()));
        Assert::contains('CorrectionLoopEntered', $this->names($r->events()));
    }

    public function test_record_validation_failure_and_finish_correction(): void
    {
        $service = $this->newService();
        $id = 'msn_app_corr';
        $at = MissionFixtures::AT;
        $service->create($this->createCommand($id));
        $service->defineScope(new DefineScope($id, ['src/Domain/Mission/Mission.php']));
        $service->startInspection(new TimedMissionCommand($id, $at));
        $service->submitInspection(new SubmitInspection($id, 'findings', $at));
        $service->approveInspection(new ApprovalCommand($id, 'user', 'tester-1', $at));
        $service->finishImplementation(new TimedMissionCommand($id, $at));

        $r = $service->recordValidation(new RecordValidation($id, 'failed', 'broken', $at));
        Assert::same(MissionState::CORRECTION_LOOP, $r->state()->toString());
        Assert::contains('ValidationFailed', $this->names($r->events()));

        $r = $service->finishCorrection(new TimedMissionCommand($id, $at));
        Assert::same(MissionState::VALIDATING, $r->state()->toString());
        Assert::contains('CorrectionLoopExited', $this->names($r->events()));
        Assert::contains('ValidationStarted', $this->names($r->events()));
    }

    public function test_inmemory_repository_load_after_save(): void
    {
        $service = $this->newService();
        $id = 'msn_app_persist';
        $service->create($this->createCommand($id));
        $r = $service->startInspection(new TimedMissionCommand($id, MissionFixtures::AT));
        Assert::same(MissionState::INSPECTING, $r->state()->toString());
        Assert::same($id, $r->missionId()->toString());
    }

    private function newService(): MissionCommandService
    {
        return new MissionCommandService(new InMemoryMissionRepository());
    }

    private function createCommand(string $id): CreateMission
    {
        return new CreateMission(
            $id,
            'github',
            'anavasis/example-target',
            'ORCH-R3 application verification',
            'user',
            'tester-1',
            MissionFixtures::AT
        );
    }

    /**
     * @param list<object> $events
     * @return list<string>
     */
    private function names(array $events): array
    {
        $names = [];
        foreach ($events as $event) {
            $names[] = $event->eventName();
        }

        return $names;
    }
}
