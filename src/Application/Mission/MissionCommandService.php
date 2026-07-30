<?php

declare(strict_types=1);

namespace Aep\Application\Mission;

use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\Command\RecordValidation;
use Aep\Application\Mission\Command\SubmitInspection;
use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\MissionRepository;
use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\InspectionFindings;
use Aep\Domain\Mission\ValueObject\MissionBrief;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\ScopePolicy;
use Aep\Domain\Mission\ValueObject\TargetRepositoryRef;
use Aep\Domain\Mission\ValueObject\ValidationResult;

/**
 * ORCH-R2 application service: orchestrate Mission use-cases only.
 */
final class MissionCommandService
{
    public function __construct(
        private MissionRepository $missions
    ) {
    }

    public function create(CreateMission $command): MissionResult
    {
        $mission = Mission::create(
            new MissionId($command->missionId()),
            new TargetRepositoryRef($command->provider(), $command->repository()),
            new MissionBrief($command->objective()),
            new ActorRef($command->actorType(), $command->actorId()),
            $command->occurredAtUtc()
        );

        return $this->persist($mission);
    }

    public function defineScope(DefineScope $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->defineScope(new ScopePolicy($command->allowedPaths(), $command->nonGoals()));

        return $this->persist($mission);
    }

    public function startInspection(TimedMissionCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->startInspection($command->occurredAtUtc());

        return $this->persist($mission);
    }

    public function submitInspection(SubmitInspection $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->submitInspection(
            new InspectionFindings($command->summary(), $command->status()),
            $command->occurredAtUtc()
        );

        return $this->persist($mission);
    }

    public function approveInspection(ApprovalCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->approveInspection(
            $this->approvalRecord($command, ApprovalRecord::SUBJECT_INSPECTION, ApprovalRecord::DECISION_APPROVED),
            $command->occurredAtUtc()
        );

        return $this->persist($mission);
    }

    public function rejectInspection(ApprovalCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->rejectInspection(
            $this->approvalRecord($command, ApprovalRecord::SUBJECT_INSPECTION, ApprovalRecord::DECISION_REJECTED),
            $command->occurredAtUtc()
        );

        return $this->persist($mission);
    }

    public function finishImplementation(TimedMissionCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->finishImplementation($command->occurredAtUtc());

        return $this->persist($mission);
    }

    public function recordValidation(RecordValidation $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $result = $command->outcome() === 'passed'
            ? ValidationResult::passed($command->reason())
            : ValidationResult::failed($command->reason());
        $mission->recordValidation($result, $command->occurredAtUtc());

        return $this->persist($mission);
    }

    public function finishCorrection(TimedMissionCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->finishCorrection($command->occurredAtUtc());

        return $this->persist($mission);
    }

    public function approveCommit(ApprovalCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->approveCommit(
            $this->approvalRecord($command, ApprovalRecord::SUBJECT_COMMIT, ApprovalRecord::DECISION_APPROVED),
            $command->occurredAtUtc()
        );

        return $this->persist($mission);
    }

    public function rejectCommit(ApprovalCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->rejectCommit(
            $this->approvalRecord($command, ApprovalRecord::SUBJECT_COMMIT, ApprovalRecord::DECISION_REJECTED),
            $command->occurredAtUtc()
        );

        return $this->persist($mission);
    }

    public function markPrReady(TimedMissionCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->markPrReady($command->occurredAtUtc());

        return $this->persist($mission);
    }

    public function complete(TimedMissionCommand $command): MissionResult
    {
        $mission = $this->load($command->missionId());
        $mission->complete($command->occurredAtUtc());

        return $this->persist($mission);
    }

    private function load(string $missionId): Mission
    {
        return $this->missions->get(new MissionId($missionId));
    }

    private function persist(Mission $mission): MissionResult
    {
        $this->missions->save($mission);
        $events = $mission->pullRecordedEvents();

        return new MissionResult($mission->id(), $mission->state(), $events);
    }

    private function approvalRecord(
        ApprovalCommand $command,
        string $subject,
        string $decision
    ): ApprovalRecord {
        return new ApprovalRecord(
            $subject,
            $decision,
            new ActorRef($command->actorType(), $command->actorId()),
            $command->occurredAtUtc(),
            $command->rationale()
        );
    }
}
