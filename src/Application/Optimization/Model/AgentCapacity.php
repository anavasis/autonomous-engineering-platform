<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class AgentCapacity
{
    public function __construct(
        private string $agentId,
        private string $role = 'implementer',
        private string $state = 'available',
        private int $maxConcurrency = 2,
        private int $activeAssignments = 0,
        private ?string $cooldownUntilUtc = null,
        private float $costWeight = 1.0,
        private float $confidence = 0.75,
    ) {}

    public function agentId(): string { return $this->agentId; }
    public function role(): string { return $this->role; }
    public function state(): string { return $this->state; }
    public function availableSlots(): int { return max(0, $this->maxConcurrency - $this->activeAssignments); }
    public function costWeight(): float { return $this->costWeight; }
    public function confidence(): float { return $this->confidence; }
    public function cooldownUntilUtc(): ?string { return $this->cooldownUntilUtc; }
    public function isRoutable(): bool
    {
        return !in_array($this->state, ['offline', 'retired'], true)
            && $this->availableSlots() > 0
            && ($this->cooldownUntilUtc === null || $this->cooldownUntilUtc === '');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'agentId' => $this->agentId,
            'role' => $this->role,
            'state' => $this->state,
            'maxConcurrency' => $this->maxConcurrency,
            'activeAssignments' => $this->activeAssignments,
            'availableSlots' => $this->availableSlots(),
            'cooldownUntilUtc' => $this->cooldownUntilUtc,
            'costWeight' => $this->costWeight,
            'confidence' => $this->confidence,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['agentId'] ?? null) ? $data['agentId'] : '',
            is_string($data['role'] ?? null) ? $data['role'] : 'implementer',
            is_string($data['state'] ?? null) ? $data['state'] : 'available',
            is_int($data['maxConcurrency'] ?? null) ? $data['maxConcurrency'] : 2,
            is_int($data['activeAssignments'] ?? null) ? $data['activeAssignments'] : 0,
            is_string($data['cooldownUntilUtc'] ?? null) ? $data['cooldownUntilUtc'] : null,
            is_numeric($data['costWeight'] ?? null) ? (float) $data['costWeight'] : 1.0,
            is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.75,
        );
    }
}
