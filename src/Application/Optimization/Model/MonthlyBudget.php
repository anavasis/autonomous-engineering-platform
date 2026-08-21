<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class MonthlyBudget
{
    public function __construct(
        private string $monthKey,
        private float $limit,
        private float $spent = 0.0,
        private float $reserved = 0.0,
        private string $scope = 'global',
    ) {}

    public function monthKey(): string { return $this->monthKey; }
    public function limit(): float { return $this->limit; }
    public function spent(): float { return $this->spent; }
    public function reserved(): float { return $this->reserved; }
    public function remaining(): float { return max(0.0, $this->limit - $this->spent - $this->reserved); }
    public function canAfford(float $amount): bool { return $amount <= $this->remaining() + 1e-9; }

    public function withReserve(float $amount): self
    {
        $c = clone $this; $c->reserved = max(0.0, $this->reserved + $amount); return $c;
    }

    public function withCommit(float $amount): self
    {
        $c = clone $this;
        $c->reserved = max(0.0, $this->reserved - $amount);
        $c->spent = max(0.0, $this->spent + $amount);
        return $c;
    }

    public function withRelease(float $amount): self
    {
        $c = clone $this; $c->reserved = max(0.0, $this->reserved - $amount); return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'monthKey' => $this->monthKey,
            'scope' => $this->scope,
            'limit' => $this->limit,
            'spent' => $this->spent,
            'reserved' => $this->reserved,
            'remaining' => $this->remaining(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['monthKey'] ?? null) ? $data['monthKey'] : gmdate('Y-m'),
            is_numeric($data['limit'] ?? null) ? (float) $data['limit'] : 2000.0,
            is_numeric($data['spent'] ?? null) ? (float) $data['spent'] : 0.0,
            is_numeric($data['reserved'] ?? null) ? (float) $data['reserved'] : 0.0,
            is_string($data['scope'] ?? null) ? $data['scope'] : 'global',
        );
    }
}
