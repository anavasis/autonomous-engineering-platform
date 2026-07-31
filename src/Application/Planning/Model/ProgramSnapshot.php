<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Model;

/**
 * Point-in-time snapshot enabling efficient replay of long-running programs.
 */
final class ProgramSnapshot
{
    /**
     * @param array<string, mixed> $programState
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $allocations
     */
    public function __construct(
        private string $snapshotId,
        private string $programId,
        private int $sequence,
        private string $atUtc,
        private array $programState,
        private array $schedule,
        private array $allocations,
        private string $lastEventId,
        private string $integrityHash = '',
    ) {
    }

    public function snapshotId(): string { return $this->snapshotId; }
    public function programId(): string { return $this->programId; }
    public function sequence(): int { return $this->sequence; }
    public function atUtc(): string { return $this->atUtc; }
    public function lastEventId(): string { return $this->lastEventId; }

    /** @return array<string, mixed> */
    public function programState(): array { return $this->programState; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'snapshotId' => $this->snapshotId,
            'programId' => $this->programId,
            'sequence' => $this->sequence,
            'atUtc' => $this->atUtc,
            'programState' => $this->programState,
            'schedule' => $this->schedule,
            'allocations' => $this->allocations,
            'lastEventId' => $this->lastEventId,
            'integrityHash' => $this->integrityHash,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['snapshotId'] ?? null) ? $data['snapshotId'] : self::makeId(),
            is_string($data['programId'] ?? null) ? $data['programId'] : '',
            is_int($data['sequence'] ?? null) ? $data['sequence'] : 0,
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_array($data['programState'] ?? null) ? $data['programState'] : [],
            is_array($data['schedule'] ?? null) ? $data['schedule'] : [],
            is_array($data['allocations'] ?? null) ? $data['allocations'] : [],
            is_string($data['lastEventId'] ?? null) ? $data['lastEventId'] : '',
            is_string($data['integrityHash'] ?? null) ? $data['integrityHash'] : '',
        );
    }

    public static function makeId(): string
    {
        return 'psnap_' . bin2hex(random_bytes(6));
    }

    public function withIntegrity(): self
    {
        $c = clone $this;
        $payload = $this->toArray();
        unset($payload['integrityHash']);
        $c->integrityHash = 'sha256:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $c;
    }
}
