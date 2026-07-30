<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Soft per-step timeout policy (wall-clock, cooperative).
 */
final class TimeoutPolicy
{
    /**
     * @param float $stepTimeoutSeconds 0 disables step timeout checks
     */
    public function __construct(
        private float $stepTimeoutSeconds = 0.0
    ) {
        if ($this->stepTimeoutSeconds < 0) {
            throw new \InvalidArgumentException('stepTimeoutSeconds must be >= 0.');
        }
    }

    public static function disabled(): self
    {
        return new self(0.0);
    }

    public function stepTimeoutSeconds(): float
    {
        return $this->stepTimeoutSeconds;
    }

    public function isEnabled(): bool
    {
        return $this->stepTimeoutSeconds > 0.0;
    }

    public function exceeds(float $elapsedSeconds): bool
    {
        return $this->isEnabled() && $elapsedSeconds > $this->stepTimeoutSeconds;
    }
}
