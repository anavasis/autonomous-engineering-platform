<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class ExecutionQuota
{
    public function __construct(
        private string $periodKey,
        private int $maxRequests,
        private int $usedRequests = 0,
        private string $period = 'daily',
    ) {}

    public function remaining(): int { return max(0, $this->maxRequests - $this->usedRequests); }
    public function canAdmit(int $n = 1): bool { return $this->remaining() >= $n; }
    public function withUse(int $n = 1): self { $c = clone $this; $c->usedRequests = max(0, $this->usedRequests + $n); return $c; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'periodKey' => $this->periodKey,
            'maxRequests' => $this->maxRequests,
            'usedRequests' => $this->usedRequests,
            'remaining' => $this->remaining(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['periodKey'] ?? null) ? $data['periodKey'] : gmdate('Y-m-d'),
            is_int($data['maxRequests'] ?? null) ? $data['maxRequests'] : 1000,
            is_int($data['usedRequests'] ?? null) ? $data['usedRequests'] : 0,
            is_string($data['period'] ?? null) ? $data['period'] : 'daily',
        );
    }
}
