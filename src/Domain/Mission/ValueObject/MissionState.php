<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Mission lifecycle state (ORCH-R1 finite set).
 */
final class MissionState
{
    public const DRAFT = 'draft';
    public const INSPECTING = 'inspecting';
    public const AWAITING_INSPECTION_APPROVAL = 'awaiting_inspection_approval';
    public const IMPLEMENTING = 'implementing';
    public const VALIDATING = 'validating';
    public const CORRECTION_LOOP = 'correction_loop';
    public const AWAITING_COMMIT_APPROVAL = 'awaiting_commit_approval';
    public const COMMITTING = 'committing';
    public const PR_READY = 'pr_ready';
    public const COMPLETED = 'completed';

    private const ALLOWED = [
        self::DRAFT,
        self::INSPECTING,
        self::AWAITING_INSPECTION_APPROVAL,
        self::IMPLEMENTING,
        self::VALIDATING,
        self::CORRECTION_LOOP,
        self::AWAITING_COMMIT_APPROVAL,
        self::COMMITTING,
        self::PR_READY,
        self::COMPLETED,
    ];

    public function __construct(
        private string $value
    ) {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Invalid MissionState: ' . $value);
        }
    }

    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function is(string $state): bool
    {
        return $this->value === $state;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALLOWED;
    }
}
