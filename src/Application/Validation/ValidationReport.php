<?php

declare(strict_types=1);

namespace Aep\Application\Validation;

/**
 * Immutable aggregate validation report.
 */
final class ValidationReport
{
    /**
     * @param list<ValidationOutcome> $outcomes
     */
    private function __construct(
        private array $outcomes,
        private bool $passed,
        private string $reason
    ) {
    }

    /**
     * @param list<ValidationOutcome> $outcomes
     */
    public static function fromOutcomes(array $outcomes): self
    {
        if ($outcomes === []) {
            throw new \InvalidArgumentException('ValidationReport requires at least one outcome.');
        }

        $failedMessages = [];
        foreach ($outcomes as $outcome) {
            if (!$outcome instanceof ValidationOutcome) {
                throw new \InvalidArgumentException('ValidationReport outcomes must be ValidationOutcome instances.');
            }
            if ($outcome->isFailed()) {
                $failedMessages[] = $outcome->stepId() . ': ' . $outcome->message();
            }
        }

        if ($failedMessages === []) {
            return new self($outcomes, true, 'all validation steps passed');
        }

        return new self($outcomes, false, implode('; ', $failedMessages));
    }

    /**
     * @return list<ValidationOutcome>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isFailed(): bool
    {
        return !$this->passed;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function outcome(): string
    {
        return $this->passed ? 'passed' : 'failed';
    }
}
