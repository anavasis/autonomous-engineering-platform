<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Model;

final class OptimizationEvent
{
    public const BUDGET_EXCEEDED = 'BudgetExceeded';
    public const QUOTA_EXCEEDED = 'QuotaExceeded';
    public const CAPACITY_RESERVED = 'CapacityReserved';
    public const CAPACITY_RELEASED = 'CapacityReleased';
    public const CAPACITY_OVERCOMMITTED = 'CapacityOvercommitted';
    public const OPTIMIZATION_DECISION = 'OptimizationDecision';
    public const PROVIDER_SELECTED = 'ProviderSelected';
    public const PROVIDER_REJECTED = 'ProviderRejected';
    public const AGENT_PREFERRED = 'AgentPreferred';
    public const EXECUTION_DELAYED = 'ExecutionDelayed';
    public const COST_FORECAST_UPDATED = 'CostForecastUpdated';
    public const ACTUAL_COST_RECORDED = 'ActualCostRecorded';
    public const OPTIMIZATION_DEGRADED = 'OptimizationDegraded';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $eventId,
        private string $type,
        private string $atUtc,
        private array $payload = [],
        private ?string $decisionId = null,
        private ?string $providerId = null,
        private ?string $programId = null,
        private ?string $missionId = null,
    ) {}

    public function eventId(): string { return $this->eventId; }
    public function type(): string { return $this->type; }
    public function atUtc(): string { return $this->atUtc; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'type' => $this->type,
            'atUtc' => $this->atUtc,
            'decisionId' => $this->decisionId,
            'providerId' => $this->providerId,
            'programId' => $this->programId,
            'missionId' => $this->missionId,
            'payload' => $this->payload,
            'message' => $this->type,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['eventId'] ?? null) ? $data['eventId'] : self::makeId(),
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            is_string($data['decisionId'] ?? null) ? $data['decisionId'] : null,
            is_string($data['providerId'] ?? null) ? $data['providerId'] : null,
            is_string($data['programId'] ?? null) ? $data['programId'] : null,
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
        );
    }

    public static function makeId(): string { return 'oev_' . bin2hex(random_bytes(8)); }
}
