<?php

declare(strict_types=1);

namespace Aep\Application\Mission\Command;

/**
 * Record a minimal validation outcome against a Mission.
 */
final class RecordValidation
{
    public function __construct(
        private string $missionId,
        private string $outcome,
        private string $reason,
        private string $occurredAtUtc
    ) {
        $this->missionId = self::requireNonEmpty($missionId, 'missionId');
        $this->outcome = self::requireNonEmpty($outcome, 'outcome');
        $this->occurredAtUtc = self::requireNonEmpty($occurredAtUtc, 'occurredAtUtc');
        $this->reason = trim($reason);
        if (!in_array($this->outcome, ['passed', 'failed'], true)) {
            throw new \InvalidArgumentException('outcome must be passed or failed.');
        }
        if ($this->outcome === 'failed' && $this->reason === '') {
            throw new \InvalidArgumentException('reason must be non-empty when outcome is failed.');
        }
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
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
