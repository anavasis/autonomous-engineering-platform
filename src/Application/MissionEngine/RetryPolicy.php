<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Retry policy for retryable step failures.
 */
final class RetryPolicy
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $backoffMs = 0
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1.');
        }
        if ($this->backoffMs < 0) {
            throw new \InvalidArgumentException('backoffMs must be >= 0.');
        }
    }

    public static function none(): self
    {
        return new self(1, 0);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function backoffMs(): int
    {
        return $this->backoffMs;
    }

    public function shouldRetry(StepResult $result, int $attempt): bool
    {
        return $result->isFailed()
            && $result->isRetryable()
            && $attempt < $this->maxAttempts;
    }
}
