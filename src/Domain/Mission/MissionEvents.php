<?php

declare(strict_types=1);

namespace Aep\Domain\Mission;

use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Domain\Mission\ValueObject\ValidationResult;

/**
 * ORCH-R1 mission domain events (lifecycle, approval, validation).
 */
abstract class MissionDomainEvent
{
    public function __construct(
        private MissionId $missionId,
        private string $occurredAtUtc,
        private string $fromState,
        private string $toState
    ) {
    }

    public function missionId(): MissionId
    {
        return $this->missionId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    public function fromState(): string
    {
        return $this->fromState;
    }

    public function toState(): string
    {
        return $this->toState;
    }

    abstract public function eventName(): string;
}

final class MissionCreated extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        private ActorRef $createdBy
    ) {
        parent::__construct($missionId, $occurredAtUtc, '', MissionState::DRAFT);
    }

    public function createdBy(): ActorRef
    {
        return $this->createdBy;
    }

    public function eventName(): string
    {
        return 'MissionCreated';
    }
}

final class InspectionStarted extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'InspectionStarted';
    }
}

final class InspectionSubmitted extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'InspectionSubmitted';
    }
}

final class InspectionApprovalGranted extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private ApprovalRecord $approval
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function approval(): ApprovalRecord
    {
        return $this->approval;
    }

    public function eventName(): string
    {
        return 'InspectionApprovalGranted';
    }
}

final class InspectionApprovalRejected extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private ApprovalRecord $approval
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function approval(): ApprovalRecord
    {
        return $this->approval;
    }

    public function eventName(): string
    {
        return 'InspectionApprovalRejected';
    }
}

final class ImplementationStarted extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'ImplementationStarted';
    }
}

final class ImplementationFinished extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'ImplementationFinished';
    }
}

final class ValidationStarted extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'ValidationStarted';
    }
}

final class ValidationPassed extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private ValidationResult $result
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    public function eventName(): string
    {
        return 'ValidationPassed';
    }
}

final class ValidationFailed extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private ValidationResult $result
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    public function eventName(): string
    {
        return 'ValidationFailed';
    }
}

final class CorrectionLoopEntered extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'CorrectionLoopEntered';
    }
}

final class CorrectionLoopExited extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'CorrectionLoopExited';
    }
}

final class CommitApprovalGranted extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private ApprovalRecord $approval
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function approval(): ApprovalRecord
    {
        return $this->approval;
    }

    public function eventName(): string
    {
        return 'CommitApprovalGranted';
    }
}

final class CommitApprovalRejected extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private ApprovalRecord $approval
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function approval(): ApprovalRecord
    {
        return $this->approval;
    }

    public function eventName(): string
    {
        return 'CommitApprovalRejected';
    }
}

final class CommitPhaseStarted extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'CommitPhaseStarted';
    }
}

final class PullRequestMarkedReady extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'PullRequestMarkedReady';
    }
}

final class MissionCompleted extends MissionDomainEvent
{
    public function eventName(): string
    {
        return 'MissionCompleted';
    }
}

final class TransitionRejected extends MissionDomainEvent
{
    public function __construct(
        MissionId $missionId,
        string $occurredAtUtc,
        string $fromState,
        string $toState,
        private string $attemptedAction,
        private string $reason
    ) {
        parent::__construct($missionId, $occurredAtUtc, $fromState, $toState);
    }

    public function attemptedAction(): string
    {
        return $this->attemptedAction;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function eventName(): string
    {
        return 'TransitionRejected';
    }
}
