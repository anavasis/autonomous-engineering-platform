<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class AgentAssignment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_STARTED = 'started';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_HANDOFF = 'handoff';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REASSIGNED = 'reassigned';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param list<string> $requiredCapabilities
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $assignmentId,
        private string $agentId,
        private string $role,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $programId = null,
        private ?string $nodeId = null,
        private ?string $missionId = null,
        private ?string $runId = null,
        private ?string $sessionId = null,
        private array $requiredCapabilities = [],
        private array $context = [],
        private ?string $preferredProviderId = null,
        private int $attempt = 1,
        private ?string $supersededBy = null,
    ) {
    }

    public function assignmentId(): string { return $this->assignmentId; }
    public function agentId(): string { return $this->agentId; }
    public function role(): string { return $this->role; }
    public function status(): string { return $this->status; }
    public function programId(): ?string { return $this->programId; }
    public function nodeId(): ?string { return $this->nodeId; }
    public function missionId(): ?string { return $this->missionId; }
    public function runId(): ?string { return $this->runId; }
    public function sessionId(): ?string { return $this->sessionId; }
    public function preferredProviderId(): ?string { return $this->preferredProviderId; }
    /** @return list<string> */
    public function requiredCapabilities(): array { return $this->requiredCapabilities; }

    public function withStatus(string $status, string $atUtc): self
    {
        $c = clone $this;
        $c->status = $status;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withLaunch(string $missionId, string $runId, ?string $sessionId, string $atUtc): self
    {
        $c = clone $this;
        $c->missionId = $missionId;
        $c->runId = $runId;
        $c->sessionId = $sessionId;
        $c->status = self::STATUS_STARTED;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withAgent(string $agentId, string $atUtc): self
    {
        $c = clone $this;
        $c->agentId = $agentId;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withSupersededBy(string $assignmentId, string $atUtc): self
    {
        $c = clone $this;
        $c->status = self::STATUS_REASSIGNED;
        $c->supersededBy = $assignmentId;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assignmentId' => $this->assignmentId,
            'agentId' => $this->agentId,
            'role' => $this->role,
            'status' => $this->status,
            'programId' => $this->programId,
            'nodeId' => $this->nodeId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'sessionId' => $this->sessionId,
            'requiredCapabilities' => $this->requiredCapabilities,
            'context' => $this->context,
            'preferredProviderId' => $this->preferredProviderId,
            'attempt' => $this->attempt,
            'supersededBy' => $this->supersededBy,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $caps = [];
        if (isset($data['requiredCapabilities']) && is_array($data['requiredCapabilities'])) {
            foreach ($data['requiredCapabilities'] as $c) {
                if (is_string($c)) {
                    $caps[] = $c;
                }
            }
        }

        return new self(
            is_string($data['assignmentId'] ?? null) ? $data['assignmentId'] : self::makeId(),
            is_string($data['agentId'] ?? null) ? $data['agentId'] : '',
            is_string($data['role'] ?? null) ? $data['role'] : Agent::ROLE_IMPLEMENTER,
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PENDING,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['programId'] ?? null) ? $data['programId'] : null,
            is_string($data['nodeId'] ?? null) ? $data['nodeId'] : null,
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_string($data['runId'] ?? null) ? $data['runId'] : null,
            is_string($data['sessionId'] ?? null) ? $data['sessionId'] : null,
            $caps,
            is_array($data['context'] ?? null) ? $data['context'] : [],
            is_string($data['preferredProviderId'] ?? null) ? $data['preferredProviderId'] : null,
            is_int($data['attempt'] ?? null) ? $data['attempt'] : 1,
            is_string($data['supersededBy'] ?? null) ? $data['supersededBy'] : null,
        );
    }

    public static function makeId(): string
    {
        return 'asgn_' . bin2hex(random_bytes(6));
    }
}
