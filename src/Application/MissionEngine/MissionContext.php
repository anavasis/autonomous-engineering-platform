<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

use Aep\Application\Execution\ExecutionService;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\Validation\ValidationPipeline;

/**
 * Per-run application context supplied to MissionSteps.
 *
 * Domain Mission never receives this object.
 */
final class MissionContext
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
        private MissionCommandService $missions,
        private CancellationToken $cancellation,
        private array $attributes = [],
        private ?string $projectId = null,
        private ?ExecutionService $execution = null,
        private ?ValidationPipeline $validation = null,
        private string $missionState = ''
    ) {
        if (trim($this->runId) === '' || trim($this->missionId) === '') {
            throw new \InvalidArgumentException('runId and missionId are required.');
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

    public function missions(): MissionCommandService
    {
        return $this->missions;
    }

    public function execution(): ?ExecutionService
    {
        return $this->execution;
    }

    public function validation(): ?ValidationPipeline
    {
        return $this->validation;
    }

    public function cancellation(): CancellationToken
    {
        return $this->cancellation;
    }

    public function missionState(): string
    {
        return $this->missionState;
    }

    public function withMissionState(string $state): self
    {
        $clone = clone $this;
        $clone->missionState = $state;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withMergedAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($this->attributes, $attributes);

        return $clone;
    }

    public function requireExecution(): ExecutionService
    {
        if ($this->execution === null) {
            throw new \RuntimeException('ExecutionService is not configured on MissionContext.');
        }

        return $this->execution;
    }

    public function requireValidation(): ValidationPipeline
    {
        if ($this->validation === null) {
            throw new \RuntimeException('ValidationPipeline is not configured on MissionContext.');
        }

        return $this->validation;
    }
}
