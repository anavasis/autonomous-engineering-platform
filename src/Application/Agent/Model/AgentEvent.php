<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class AgentEvent
{
    public const AGENT_REGISTERED = 'AgentRegistered';
    public const ASSIGNMENT_CREATED = 'AssignmentCreated';
    public const ASSIGNMENT_STARTED = 'AssignmentStarted';
    public const ASSIGNMENT_COMPLETED = 'AssignmentCompleted';
    public const ASSIGNMENT_FAILED = 'AssignmentFailed';
    public const AGENT_UNAVAILABLE = 'AgentUnavailable';
    public const ASSIGNMENT_REASSIGNED = 'AssignmentReassigned';
    public const AGENT_RETIRED = 'AgentRetired';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $eventId,
        private string $type,
        private string $atUtc,
        private ?string $agentId = null,
        private ?string $assignmentId = null,
        private ?string $programId = null,
        private ?string $nodeId = null,
        private ?string $missionId = null,
        private array $payload = [],
    ) {
    }

    public function eventId(): string { return $this->eventId; }
    public function type(): string { return $this->type; }
    public function agentId(): ?string { return $this->agentId; }
    public function assignmentId(): ?string { return $this->assignmentId; }
    public function atUtc(): string { return $this->atUtc; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'type' => $this->type,
            'agentId' => $this->agentId,
            'assignmentId' => $this->assignmentId,
            'programId' => $this->programId,
            'nodeId' => $this->nodeId,
            'missionId' => $this->missionId,
            'atUtc' => $this->atUtc,
            'payload' => $this->payload,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['eventId'] ?? null) ? $data['eventId'] : self::makeId(),
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_string($data['agentId'] ?? null) ? $data['agentId'] : null,
            is_string($data['assignmentId'] ?? null) ? $data['assignmentId'] : null,
            is_string($data['programId'] ?? null) ? $data['programId'] : null,
            is_string($data['nodeId'] ?? null) ? $data['nodeId'] : null,
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    public static function makeId(): string
    {
        return 'aev_' . bin2hex(random_bytes(8));
    }
}
