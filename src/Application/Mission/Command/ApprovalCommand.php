<?php

declare(strict_types=1);

namespace Aep\Application\Mission\Command;

/**
 * Shared payload for inspection/commit approval and rejection use-cases.
 */
final class ApprovalCommand
{
    public function __construct(
        private string $missionId,
        private string $actorType,
        private string $actorId,
        private string $occurredAtUtc,
        private string $rationale = ''
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        $this->actorType = self::requireNonEmpty($actorType, 'actorType');
        $this->actorId = self::requireNonEmpty($actorId, 'actorId');
        $this->occurredAtUtc = self::requireNonEmpty($occurredAtUtc, 'occurredAtUtc');
        $this->rationale = trim($rationale);
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    public function rationale(): string
    {
        return $this->rationale;
    }

    private static function requireNonEmpty(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must be non-empty.');
        }

        return $value;
    }
}
