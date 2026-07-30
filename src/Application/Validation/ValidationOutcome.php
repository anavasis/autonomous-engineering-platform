<?php

declare(strict_types=1);

namespace Aep\Application\Validation;

/**
 * Immutable per-step validation outcome.
 */
final class ValidationOutcome
{
    private function __construct(
        private string $stepId,
        private bool $passed,
        private string $message
    ) {
    }

    public static function passed(string $stepId, string $message = ''): self
    {
        $stepId = trim($stepId);
        if ($stepId === '') {
            throw new \InvalidArgumentException('stepId must be non-empty.');
        }

        return new self($stepId, true, trim($message));
    }

    public static function failed(string $stepId, string $message): self
    {
        $stepId = trim($stepId);
        $message = trim($message);
        if ($stepId === '') {
            throw new \InvalidArgumentException('stepId must be non-empty.');
        }
        if ($message === '') {
            throw new \InvalidArgumentException('failed message must be non-empty.');
        }

        return new self($stepId, false, $message);
    }

    public function stepId(): string
    {
        return $this->stepId;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isFailed(): bool
    {
        return !$this->passed;
    }

    public function message(): string
    {
        return $this->message;
    }
}
