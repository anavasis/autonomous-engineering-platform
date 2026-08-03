<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Model;

/**
 * Observation snapshot used by AcceptanceValidator (no project-specific fields).
 */
final class AcceptanceContext
{
    /**
     * @param list<array<string, mixed>> $artifacts
     * @param list<array<string, mixed>> $runtimeEvents
     * @param array<string, mixed> $providerUsage
     * @param list<string> $workspaceRoots filesystem roots for file/directory rules
     * @param array<string, mixed> $signals generic boolean/string signals (tests_executed, build_completed, …)
     */
    public function __construct(
        private ?string $missionId,
        private ?string $runId,
        private ?string $jobId,
        private ?string $engineState,
        private ?string $runtimeStatus,
        private bool $projectStarted,
        private float $executionTimeSeconds,
        private array $artifacts,
        private array $runtimeEvents,
        private array $providerUsage,
        private array $workspaceRoots,
        private array $signals = [],
    ) {
    }

    public function missionId(): ?string
    {
        return $this->missionId;
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function jobId(): ?string
    {
        return $this->jobId;
    }

    public function engineState(): ?string
    {
        return $this->engineState;
    }

    public function runtimeStatus(): ?string
    {
        return $this->runtimeStatus;
    }

    public function projectStarted(): bool
    {
        return $this->projectStarted;
    }

    public function executionTimeSeconds(): float
    {
        return $this->executionTimeSeconds;
    }

    /** @return list<array<string, mixed>> */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    /** @return list<array<string, mixed>> */
    public function runtimeEvents(): array
    {
        return $this->runtimeEvents;
    }

    /** @return array<string, mixed> */
    public function providerUsage(): array
    {
        return $this->providerUsage;
    }

    /** @return list<string> */
    public function workspaceRoots(): array
    {
        return $this->workspaceRoots;
    }

    /** @return array<string, mixed> */
    public function signals(): array
    {
        return $this->signals;
    }

    public function signal(string $key): mixed
    {
        return $this->signals[$key] ?? null;
    }
}
