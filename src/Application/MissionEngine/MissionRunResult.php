<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Outcome of a MissionEngine start/resume/cancel/status call.
 *
 * Distinct from Application\Mission\MissionResult (command-service outcome).
 */
final class MissionRunResult
{
    /**
     * @param list<string> $completedStepIds
     */
    public function __construct(
        private string $runId,
        private string $missionId,
        private MissionRunState $engineState,
        private string $missionState,
        private int $progressPercent,
        private array $completedStepIds,
        private string $message,
        private ?string $failedStepId = null,
        private ?string $currentStepId = null
    ) {
        if ($this->progressPercent < 0 || $this->progressPercent > 100) {
            throw new \InvalidArgumentException('progressPercent must be 0..100.');
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function engineState(): MissionRunState
    {
        return $this->engineState;
    }

    public function missionState(): string
    {
        return $this->missionState;
    }

    public function progressPercent(): int
    {
        return $this->progressPercent;
    }

    /**
     * @return list<string>
     */
    public function completedStepIds(): array
    {
        return $this->completedStepIds;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function failedStepId(): ?string
    {
        return $this->failedStepId;
    }

    public function currentStepId(): ?string
    {
        return $this->currentStepId;
    }
}
