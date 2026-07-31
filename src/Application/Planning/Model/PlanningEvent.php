<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Model;

final class PlanningEvent
{
    public const PROGRAM_CREATED = 'ProgramCreated';
    public const PROGRAM_PLANNED = 'ProgramPlanned';
    public const NODE_QUEUED = 'NodeQueued';
    public const NODE_STARTED = 'NodeStarted';
    public const NODE_COMPLETED = 'NodeCompleted';
    public const NODE_FAILED = 'NodeFailed';
    public const PROGRAM_REPLANNED = 'ProgramReplanned';
    public const PROGRAM_PAUSED = 'ProgramPaused';
    public const PROGRAM_RESUMED = 'ProgramResumed';
    public const PROGRAM_COMPLETED = 'ProgramCompleted';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $eventId,
        private string $type,
        private string $programId,
        private string $atUtc,
        private array $payload = [],
        private ?string $nodeId = null,
    ) {
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function programId(): string
    {
        return $this->programId;
    }

    public function nodeId(): ?string
    {
        return $this->nodeId;
    }

    public function atUtc(): string
    {
        return $this->atUtc;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'type' => $this->type,
            'programId' => $this->programId,
            'nodeId' => $this->nodeId,
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
            is_string($data['programId'] ?? null) ? $data['programId'] : '',
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            is_string($data['nodeId'] ?? null) ? $data['nodeId'] : null,
        );
    }

    public static function makeId(): string
    {
        return 'pev_' . bin2hex(random_bytes(8));
    }
}
