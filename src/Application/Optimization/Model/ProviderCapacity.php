<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class ProviderCapacity
{
    public function __construct(
        private string $providerId,
        private string $state = 'available',
        private int $maxSessions = 4,
        private int $activeSessions = 0,
        private int $reservedSessions = 0,
        private float $successRate = 0.8,
        private float $avgLatencyMs = 1000.0,
        private float $failureRate = 0.1,
        private float $confidence = 0.75,
    ) {}

    public function providerId(): string { return $this->providerId; }
    public function state(): string { return $this->state; }
    public function availableSlots(): int { return max(0, $this->maxSessions - $this->activeSessions - $this->reservedSessions); }
    public function successRate(): float { return $this->successRate; }
    public function avgLatencyMs(): float { return $this->avgLatencyMs; }
    public function failureRate(): float { return $this->failureRate; }
    public function confidence(): float { return $this->confidence; }
    public function isRoutable(): bool { return !in_array($this->state, ['offline', 'overcommitted'], true) && $this->availableSlots() > 0; }

    public function withReserve(int $n = 1): self
    {
        $c = clone $this; $c->reservedSessions = max(0, $this->reservedSessions + $n);
        if ($c->availableSlots() === 0 && $c->state === 'available') { $c->state = 'allocated'; }
        return $c;
    }

    public function withRelease(int $n = 1): self
    {
        $c = clone $this; $c->reservedSessions = max(0, $this->reservedSessions - $n);
        if ($c->reservedSessions === 0 && $c->activeSessions === 0) { $c->state = 'available'; }
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'state' => $this->state,
            'maxSessions' => $this->maxSessions,
            'activeSessions' => $this->activeSessions,
            'reservedSessions' => $this->reservedSessions,
            'availableSlots' => $this->availableSlots(),
            'successRate' => $this->successRate,
            'avgLatencyMs' => $this->avgLatencyMs,
            'failureRate' => $this->failureRate,
            'confidence' => $this->confidence,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['providerId'] ?? null) ? $data['providerId'] : '',
            is_string($data['state'] ?? null) ? $data['state'] : 'available',
            is_int($data['maxSessions'] ?? null) ? $data['maxSessions'] : 4,
            is_int($data['activeSessions'] ?? null) ? $data['activeSessions'] : 0,
            is_int($data['reservedSessions'] ?? null) ? $data['reservedSessions'] : 0,
            is_numeric($data['successRate'] ?? null) ? (float) $data['successRate'] : 0.8,
            is_numeric($data['avgLatencyMs'] ?? null) ? (float) $data['avgLatencyMs'] : 1000.0,
            is_numeric($data['failureRate'] ?? null) ? (float) $data['failureRate'] : 0.1,
            is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.75,
        );
    }
}
