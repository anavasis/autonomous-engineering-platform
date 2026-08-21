<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class CostModel
{
    public function __construct(
        private string $providerId,
        private float $fixedCost = 0.0,
        private float $perRequest = 0.01,
        private float $perToken = 0.000002,
        private float $perMinute = 0.02,
        private string $currency = 'USD',
    ) {}

    public function providerId(): string { return $this->providerId; }
    public function fixedCost(): float { return $this->fixedCost; }
    public function perRequest(): float { return $this->perRequest; }
    public function perToken(): float { return $this->perToken; }
    public function perMinute(): float { return $this->perMinute; }

    public function estimate(float $tokens = 2000.0, float $minutes = 1.0, int $requests = 1): float
    {
        return $this->fixedCost + ($this->perRequest * $requests) + ($this->perToken * $tokens) + ($this->perMinute * $minutes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'fixedCost' => $this->fixedCost,
            'perRequest' => $this->perRequest,
            'perToken' => $this->perToken,
            'perMinute' => $this->perMinute,
            'currency' => $this->currency,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['providerId'] ?? null) ? $data['providerId'] : '',
            is_numeric($data['fixedCost'] ?? null) ? (float) $data['fixedCost'] : 0.0,
            is_numeric($data['perRequest'] ?? null) ? (float) $data['perRequest'] : 0.01,
            is_numeric($data['perToken'] ?? null) ? (float) $data['perToken'] : 0.000002,
            is_numeric($data['perMinute'] ?? null) ? (float) $data['perMinute'] : 0.02,
            is_string($data['currency'] ?? null) ? $data['currency'] : 'USD',
        );
    }
}
