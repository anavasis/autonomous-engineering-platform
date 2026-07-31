<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Model;

final class ProviderSessionRequest
{
    /**
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed>|null $checkpoint
     */
    public function __construct(
        private string $sessionId,
        private string $missionId,
        private string $runId,
        private string $action,
        private PromptBundle $prompt,
        private string $workspacePath,
        private array $allowedPaths = ['src/'],
        private array $nonGoals = [],
        private int $timeoutSeconds = 300,
        private array $providerOptions = [],
        private ?array $checkpoint = null,
    ) {
        $this->sessionId = trim($sessionId);
        $this->missionId = trim($missionId);
        $this->runId = trim($runId);
        $this->action = trim($action);
        if ($this->sessionId === '' || $this->missionId === '' || $this->action === '') {
            throw new \InvalidArgumentException('sessionId, missionId, and action are required.');
        }
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function prompt(): PromptBundle
    {
        return $this->prompt;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    /** @return list<string> */
    public function allowedPaths(): array
    {
        return $this->allowedPaths;
    }

    /** @return list<string> */
    public function nonGoals(): array
    {
        return $this->nonGoals;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** @return array<string, mixed> */
    public function providerOptions(): array
    {
        return $this->providerOptions;
    }

    /** @return array<string, mixed>|null */
    public function checkpoint(): ?array
    {
        return $this->checkpoint;
    }
}
