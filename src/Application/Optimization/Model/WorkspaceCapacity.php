<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class WorkspaceCapacity
{
    public function __construct(
        private string $state = 'available',
        private int $maxConcurrent = 16,
        private int $inUse = 0,
        private int $maxBytes = 524288000,
        private int $usedBytes = 0,
        private int $snapshotCount = 0,
        private int $maxSnapshots = 100,
    ) {}

    public function state(): string { return $this->state; }
    public function availableSlots(): int { return max(0, $this->maxConcurrent - $this->inUse); }
    public function diskAvailable(): int { return max(0, $this->maxBytes - $this->usedBytes); }
    public function isRoutable(): bool { return $this->availableSlots() > 0 && $this->diskAvailable() > 0 && $this->state !== 'offline'; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'maxConcurrent' => $this->maxConcurrent,
            'inUse' => $this->inUse,
            'availableSlots' => $this->availableSlots(),
            'maxBytes' => $this->maxBytes,
            'usedBytes' => $this->usedBytes,
            'diskAvailable' => $this->diskAvailable(),
            'snapshotCount' => $this->snapshotCount,
            'maxSnapshots' => $this->maxSnapshots,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['state'] ?? null) ? $data['state'] : 'available',
            is_int($data['maxConcurrent'] ?? null) ? $data['maxConcurrent'] : 16,
            is_int($data['inUse'] ?? null) ? $data['inUse'] : 0,
            is_int($data['maxBytes'] ?? null) ? $data['maxBytes'] : 524288000,
            is_int($data['usedBytes'] ?? null) ? $data['usedBytes'] : 0,
            is_int($data['snapshotCount'] ?? null) ? $data['snapshotCount'] : 0,
            is_int($data['maxSnapshots'] ?? null) ? $data['maxSnapshots'] : 100,
        );
    }
}
