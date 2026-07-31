<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class AgentSession
{
    public function __construct(
        private string $sessionId,
        private string $assignmentId,
        private string $agentId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $missionId = null,
        private ?string $runId = null,
        private ?string $executionSessionId = null,
        private ?string $workspaceId = null,
    ) {
    }

    public function sessionId(): string { return $this->sessionId; }
    public function assignmentId(): string { return $this->assignmentId; }
    public function agentId(): string { return $this->agentId; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'assignmentId' => $this->assignmentId,
            'agentId' => $this->agentId,
            'status' => $this->status,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'executionSessionId' => $this->executionSessionId,
            'workspaceId' => $this->workspaceId,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['sessionId'] ?? null) ? $data['sessionId'] : self::makeId(),
            is_string($data['assignmentId'] ?? null) ? $data['assignmentId'] : '',
            is_string($data['agentId'] ?? null) ? $data['agentId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : 'open',
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_string($data['runId'] ?? null) ? $data['runId'] : null,
            is_string($data['executionSessionId'] ?? null) ? $data['executionSessionId'] : null,
            is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : null,
        );
    }

    public static function makeId(): string
    {
        return 'asess_' . bin2hex(random_bytes(6));
    }
}
