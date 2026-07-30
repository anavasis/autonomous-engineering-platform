<?php

declare(strict_types=1);

namespace Aep\Domain\Mission\ValueObject;

/**
 * Minimal validation outcome for FSM branching. Not a validation pipeline.
 */
final class ValidationResult
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';

    private function __construct(
        private string $outcome,
        private string $reason
    ) {
    }

    public static function passed(string $reason = ''): self
    {
        return new self(self::PASSED, trim($reason));
    }

    public static function failed(string $reason): self
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('ValidationResult failed reason must be non-empty.');
        }

        return new self(self::FAILED, $reason);
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isPassed(): bool
    {
        return $this->outcome === self::PASSED;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::FAILED;
    }
}
