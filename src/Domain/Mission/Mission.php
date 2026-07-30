<?php

declare(strict_types=1);

namespace Aep\Domain\Mission;

use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\InspectionFindings;
use Aep\Domain\Mission\ValueObject\MissionBrief;
use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Domain\Mission\ValueObject\ScopePolicy;
use Aep\Domain\Mission\ValueObject\TargetRepositoryRef;
use Aep\Domain\Mission\ValueObject\ValidationResult;

/**
 * ORCH-R1 Mission aggregate root. Owns lifecycle FSM, invariants, and domain events.
 */
final class Mission
{
    /** @var list<MissionDomainEvent> */
    private array $recordedEvents = [];

    private MissionState $state;
    private ?ScopePolicy $scopePolicy = null;
    private ?InspectionFindings $inspectionFindings = null;
    private ?ApprovalRecord $inspectionApproval = null;
    private ?ApprovalRecord $commitApproval = null;
    private ?ValidationResult $lastValidationResult = null;

    private function __construct(
        private MissionId $id,
        private TargetRepositoryRef $target,
        private MissionBrief $brief,
        private ActorRef $createdBy,
        private string $createdAtUtc
    ) {
        $this->state = MissionState::draft();
    }

    public static function create(
        MissionId $id,
        TargetRepositoryRef $target,
        MissionBrief $brief,
        ActorRef $createdBy,
        string $createdAtUtc
    ): self {
        $createdAtUtc = self::requireUtc($createdAtUtc, 'createdAtUtc');
        $mission = new self($id, $target, $brief, $createdBy, $createdAtUtc);
        $mission->record(new MissionCreated($id, $createdAtUtc, $createdBy));

        return $mission;
    }

    /**
     * Restore a Mission from a persistence snapshot.
     * Does not emit domain events or execute transitions.
     */
    public static function reconstitute(
        MissionId $id,
        TargetRepositoryRef $target,
        MissionBrief $brief,
        ActorRef $createdBy,
        string $createdAtUtc,
        MissionState $state,
        ?ScopePolicy $scopePolicy = null,
        ?InspectionFindings $inspectionFindings = null,
        ?ApprovalRecord $inspectionApproval = null,
        ?ApprovalRecord $commitApproval = null,
        ?ValidationResult $lastValidationResult = null
    ): self {
        $createdAtUtc = self::requireUtc($createdAtUtc, 'createdAtUtc');
        $mission = new self($id, $target, $brief, $createdBy, $createdAtUtc);
        $mission->state = $state;
        $mission->scopePolicy = $scopePolicy;
        $mission->inspectionFindings = $inspectionFindings;
        $mission->inspectionApproval = $inspectionApproval;
        $mission->commitApproval = $commitApproval;
        $mission->lastValidationResult = $lastValidationResult;
        $mission->recordedEvents = [];

        return $mission;
    }

    public function id(): MissionId
    {
        return $this->id;
    }

    public function state(): MissionState
    {
        return $this->state;
    }

    public function target(): TargetRepositoryRef
    {
        return $this->target;
    }

    public function brief(): MissionBrief
    {
        return $this->brief;
    }

    public function createdBy(): ActorRef
    {
        return $this->createdBy;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function scopePolicy(): ?ScopePolicy
    {
        return $this->scopePolicy;
    }

    public function inspectionFindings(): ?InspectionFindings
    {
        return $this->inspectionFindings;
    }

    public function inspectionApproval(): ?ApprovalRecord
    {
        return $this->inspectionApproval;
    }

    public function commitApproval(): ?ApprovalRecord
    {
        return $this->commitApproval;
    }

    public function lastValidationResult(): ?ValidationResult
    {
        return $this->lastValidationResult;
    }

    /**
     * @return list<MissionDomainEvent>
     */
    public function recordedEvents(): array
    {
        return $this->recordedEvents;
    }

    /**
     * @return list<MissionDomainEvent>
     */
    public function pullRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Allowed in draft or inspecting only.
     */
    public function defineScope(ScopePolicy $scopePolicy): void
    {
        $this->assertNotCompleted();
        if (!$this->state->is(MissionState::DRAFT) && !$this->state->is(MissionState::INSPECTING)) {
            throw $this->illegal('defineScope', 'Scope may only be defined in draft or inspecting.');
        }
        $this->scopePolicy = $scopePolicy;
    }

    public function startInspection(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->transition(
            MissionState::DRAFT,
            MissionState::INSPECTING,
            $occurredAtUtc,
            static fn (MissionId $id, string $at, string $from, string $to): MissionDomainEvent => new InspectionStarted($id, $at, $from, $to)
        );
    }

    public function submitInspection(InspectionFindings $findings, string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->inspectionFindings = $findings;
        $this->transition(
            MissionState::INSPECTING,
            MissionState::AWAITING_INSPECTION_APPROVAL,
            $occurredAtUtc,
            static fn (MissionId $id, string $at, string $from, string $to): MissionDomainEvent => new InspectionSubmitted($id, $at, $from, $to)
        );
    }

    public function approveInspection(ApprovalRecord $approval, string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->assertApproval($approval, ApprovalRecord::SUBJECT_INSPECTION, true);
        if ($this->scopePolicy === null) {
            throw $this->illegal('approveInspection', 'ScopePolicy is required before implementing.');
        }
        if ($this->inspectionFindings === null) {
            throw $this->illegal('approveInspection', 'InspectionFindings are required before approval.');
        }
        $this->inspectionApproval = $approval;
        $from = $this->state->toString();
        $this->transition(
            MissionState::AWAITING_INSPECTION_APPROVAL,
            MissionState::IMPLEMENTING,
            $occurredAtUtc,
            fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new InspectionApprovalGranted($id, $at, $fromState, $toState, $approval)
        );
        $this->record(new ImplementationStarted($this->id, $occurredAtUtc, $from, MissionState::IMPLEMENTING));
    }

    public function rejectInspection(ApprovalRecord $approval, string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->assertApproval($approval, ApprovalRecord::SUBJECT_INSPECTION, false);
        $this->inspectionApproval = $approval;
        $this->transition(
            MissionState::AWAITING_INSPECTION_APPROVAL,
            MissionState::INSPECTING,
            $occurredAtUtc,
            fn (MissionId $id, string $at, string $from, string $to): MissionDomainEvent => new InspectionApprovalRejected($id, $at, $from, $to, $approval)
        );
    }

    public function finishImplementation(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $from = $this->state->toString();
        $this->transition(
            MissionState::IMPLEMENTING,
            MissionState::VALIDATING,
            $occurredAtUtc,
            static fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new ImplementationFinished($id, $at, $fromState, $toState)
        );
        $this->record(new ValidationStarted($this->id, $occurredAtUtc, $from, MissionState::VALIDATING));
    }

    public function recordValidation(ValidationResult $result, string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        if (!$this->state->is(MissionState::VALIDATING)) {
            throw $this->illegal('recordValidation', 'Validation can only be recorded in validating.');
        }
        $this->lastValidationResult = $result;
        $from = $this->state->toString();

        if ($result->isPassed()) {
            $this->transition(
                MissionState::VALIDATING,
                MissionState::AWAITING_COMMIT_APPROVAL,
                $occurredAtUtc,
                fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new ValidationPassed($id, $at, $fromState, $toState, $result)
            );

            return;
        }

        $this->transition(
            MissionState::VALIDATING,
            MissionState::CORRECTION_LOOP,
            $occurredAtUtc,
            fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new ValidationFailed($id, $at, $fromState, $toState, $result)
        );
        $this->record(new CorrectionLoopEntered($this->id, $occurredAtUtc, $from, MissionState::CORRECTION_LOOP));
    }

    public function finishCorrection(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $from = $this->state->toString();
        $this->transition(
            MissionState::CORRECTION_LOOP,
            MissionState::VALIDATING,
            $occurredAtUtc,
            static fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new CorrectionLoopExited($id, $at, $fromState, $toState)
        );
        $this->record(new ValidationStarted($this->id, $occurredAtUtc, $from, MissionState::VALIDATING));
    }

    public function approveCommit(ApprovalRecord $approval, string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->assertApproval($approval, ApprovalRecord::SUBJECT_COMMIT, true);
        $this->commitApproval = $approval;
        $from = $this->state->toString();
        $this->transition(
            MissionState::AWAITING_COMMIT_APPROVAL,
            MissionState::COMMITTING,
            $occurredAtUtc,
            fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new CommitApprovalGranted($id, $at, $fromState, $toState, $approval)
        );
        $this->record(new CommitPhaseStarted($this->id, $occurredAtUtc, $from, MissionState::COMMITTING));
    }

    public function rejectCommit(ApprovalRecord $approval, string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->assertApproval($approval, ApprovalRecord::SUBJECT_COMMIT, false);
        $this->commitApproval = $approval;
        $from = $this->state->toString();
        $this->transition(
            MissionState::AWAITING_COMMIT_APPROVAL,
            MissionState::CORRECTION_LOOP,
            $occurredAtUtc,
            fn (MissionId $id, string $at, string $fromState, string $toState): MissionDomainEvent => new CommitApprovalRejected($id, $at, $fromState, $toState, $approval)
        );
        $this->record(new CorrectionLoopEntered($this->id, $occurredAtUtc, $from, MissionState::CORRECTION_LOOP));
    }

    /**
     * Advances committing → pr_ready without Git side effects (fact acknowledgment only).
     */
    public function markPrReady(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->transition(
            MissionState::COMMITTING,
            MissionState::PR_READY,
            $occurredAtUtc,
            static fn (MissionId $id, string $at, string $from, string $to): MissionDomainEvent => new PullRequestMarkedReady($id, $at, $from, $to)
        );
    }

    public function complete(string $occurredAtUtc): void
    {
        $occurredAtUtc = self::requireUtc($occurredAtUtc, 'occurredAtUtc');
        $this->transition(
            MissionState::PR_READY,
            MissionState::COMPLETED,
            $occurredAtUtc,
            static fn (MissionId $id, string $at, string $from, string $to): MissionDomainEvent => new MissionCompleted($id, $at, $from, $to)
        );
    }

    /**
     * @param callable(MissionId, string, string, string): MissionDomainEvent $eventFactory
     */
    private function transition(
        string $expectedFrom,
        string $to,
        string $occurredAtUtc,
        callable $eventFactory
    ): void {
        $this->assertNotCompleted();
        if (!$this->state->is($expectedFrom)) {
            throw $this->illegal(
                'transition:' . $to,
                'Expected state ' . $expectedFrom . ' but was ' . $this->state->toString() . '.'
            );
        }
        $from = $this->state->toString();
        $this->state = new MissionState($to);
        $this->record($eventFactory($this->id, $occurredAtUtc, $from, $to));
    }

    private function assertNotCompleted(): void
    {
        if ($this->state->is(MissionState::COMPLETED)) {
            throw $this->illegal('mutate', 'completed is terminal; no further transitions.');
        }
    }

    private function assertApproval(ApprovalRecord $approval, string $subject, bool $mustApprove): void
    {
        if ($approval->subject() !== $subject) {
            throw $this->illegal('approval', 'Approval subject must be ' . $subject . '.');
        }
        if ($mustApprove && !$approval->isApproved()) {
            throw $this->illegal('approval', 'Approval decision must be approved.');
        }
        if (!$mustApprove && $approval->isApproved()) {
            throw $this->illegal('approval', 'Rejection decision must be rejected.');
        }
    }

    private function record(MissionDomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    private function illegal(string $action, string $reason): \DomainException
    {
        return new \DomainException('Mission ' . $this->id->toString() . ' rejected "' . $action . '": ' . $reason);
    }

    private static function requireUtc(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty UTC timestamp string.');
        }

        return $value;
    }
}
