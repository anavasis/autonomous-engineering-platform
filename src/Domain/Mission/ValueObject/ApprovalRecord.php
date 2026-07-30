<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Approval or rejection evidence for inspection or commit gates.
 */
final class ApprovalRecord
{
    public const SUBJECT_INSPECTION = 'inspection';
    public const SUBJECT_COMMIT = 'commit';

    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    public function __construct(
        private string $subject,
        private string $decision,
        private ActorRef $actor,
        private string $occurredAtUtc,
        private string $rationale = ''
    ) {
        if (!in_array($subject, [self::SUBJECT_INSPECTION, self::SUBJECT_COMMIT], true)) {
            throw new \InvalidArgumentException('ApprovalRecord subject must be inspection or commit.');
        }
        if (!in_array($decision, [self::DECISION_APPROVED, self::DECISION_REJECTED], true)) {
            throw new \InvalidArgumentException('ApprovalRecord decision must be approved or rejected.');
        }
        $occurredAtUtc = trim($occurredAtUtc);
        if ($occurredAtUtc === '') {
            throw new \InvalidArgumentException('ApprovalRecord occurredAtUtc must be non-empty.');
        }
        $this->occurredAtUtc = $occurredAtUtc;
        $this->rationale = trim($rationale);
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function decision(): string
    {
        return $this->decision;
    }

    public function actor(): ActorRef
    {
        return $this->actor;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    public function rationale(): string
    {
        return $this->rationale;
    }

    public function isApproved(): bool
    {
        return $this->decision === self::DECISION_APPROVED;
    }
}
