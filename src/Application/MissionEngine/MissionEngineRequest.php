<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

/**
 * Start-run request for MissionEngine.
 */
final class MissionEngineRequest
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $runId,
        private string $missionId,
        private string $occurredAtUtc,
        private string $actorType,
        private string $actorId,
        private array $attributes = [],
        private ?string $projectId = null,
        private ?RetryPolicy $retryPolicy = null,
        private ?TimeoutPolicy $timeoutPolicy = null
    ) {
        $this->runId = trim($runId);
        $this->missionId = trim($missionId);
        $this->occurredAtUtc = trim($occurredAtUtc);
        $this->actorType = trim($actorType);
        $this->actorId = trim($actorId);
        if ($this->runId === '' || preg_match('/[^A-Za-z0-9_-]/', $this->runId) === 1) {
            throw new \InvalidArgumentException('runId must be filesystem-safe [A-Za-z0-9_-].');
        }
        if ($this->missionId === '') {
            throw new \InvalidArgumentException('missionId must be non-empty.');
        }
        if ($this->occurredAtUtc === '' || $this->actorType === '' || $this->actorId === '') {
            throw new \InvalidArgumentException('occurredAtUtc, actorType, and actorId are required.');
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

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAtUtc;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy ?? new RetryPolicy();
    }

    public function timeoutPolicy(): TimeoutPolicy
    {
        return $this->timeoutPolicy ?? TimeoutPolicy::disabled();
    }
}
