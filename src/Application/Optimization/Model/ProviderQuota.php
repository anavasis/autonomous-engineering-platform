<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class ProviderQuota
{
    public function __construct(
        private string $providerId,
        private string $periodKey,
        private int $maxRequests,
        private int $usedRequests = 0,
        private float $maxCost = 100.0,
        private float $spentCost = 0.0,
    ) {}

    public function providerId(): string { return $this->providerId; }
    public function remainingRequests(): int { return max(0, $this->maxRequests - $this->usedRequests); }
    public function remainingCost(): float { return max(0.0, $this->maxCost - $this->spentCost); }
    public function canAdmit(float $estimatedCost, int $requests = 1): bool
    {
        return $this->remainingRequests() >= $requests && $estimatedCost <= $this->remainingCost() + 1e-9;
    }

    public function withUse(float $cost, int $requests = 1): self
    {
        $c = clone $this;
        $c->usedRequests = max(0, $this->usedRequests + $requests);
        $c->spentCost = max(0.0, $this->spentCost + $cost);
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'periodKey' => $this->periodKey,
            'maxRequests' => $this->maxRequests,
            'usedRequests' => $this->usedRequests,
            'remainingRequests' => $this->remainingRequests(),
            'maxCost' => $this->maxCost,
            'spentCost' => $this->spentCost,
            'remainingCost' => $this->remainingCost(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['providerId'] ?? null) ? $data['providerId'] : '',
            is_string($data['periodKey'] ?? null) ? $data['periodKey'] : gmdate('Y-m-d'),
            is_int($data['maxRequests'] ?? null) ? $data['maxRequests'] : 500,
            is_int($data['usedRequests'] ?? null) ? $data['usedRequests'] : 0,
            is_numeric($data['maxCost'] ?? null) ? (float) $data['maxCost'] : 100.0,
            is_numeric($data['spentCost'] ?? null) ? (float) $data['spentCost'] : 0.0,
        );
    }
}
