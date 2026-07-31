<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Model;

final class Agent
{
    public const SCHEMA_VERSION = 1;

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_WORKING = 'working';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_HANDOFF = 'handoff';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_RETIRED = 'retired';

    public const ROLE_PLANNER = 'planner';
    public const ROLE_ARCHITECT = 'architect';
    public const ROLE_IMPLEMENTER = 'implementer';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_QA = 'qa';
    public const ROLE_DOCUMENTATION = 'documentation';
    public const ROLE_SECURITY = 'security';
    public const ROLE_PERFORMANCE = 'performance';

    /**
     * @param array<string, mixed> $compatibility
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        private string $agentId,
        private string $name,
        private string $role,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private AgentProfile $profile = new AgentProfile(),
        private AgentHealth $health = new AgentHealth(),
        private array $metrics = [],
        private array $compatibility = [],
        private string $reproducibilityFingerprint = '',
        private string $integrityHash = '',
        private int $activeAssignments = 0,
        private int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    public function agentId(): string { return $this->agentId; }
    public function name(): string { return $this->name; }
    public function role(): string { return $this->role; }
    public function status(): string { return $this->status; }
    public function profile(): AgentProfile { return $this->profile; }
    public function health(): AgentHealth { return $this->health; }
    public function activeAssignments(): int { return $this->activeAssignments; }
    /** @return array<string, mixed> */
    public function metrics(): array { return $this->metrics; }
    public function reproducibilityFingerprint(): string { return $this->reproducibilityFingerprint; }
    public function integrityHash(): string { return $this->integrityHash; }
    public function updatedAtUtc(): string { return $this->updatedAtUtc; }

    public function availableSlots(): int
    {
        return max(0, $this->profile->maxConcurrency() - $this->activeAssignments);
    }

    public function isRoutable(): bool
    {
        return !in_array($this->status, [self::STATUS_RETIRED, self::STATUS_OFFLINE], true)
            && $this->health->isHealthy()
            && $this->availableSlots() > 0;
    }

    public function withStatus(string $status, string $atUtc): self
    {
        $c = clone $this;
        $c->status = $status;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withHealth(AgentHealth $health, string $atUtc): self
    {
        $c = clone $this;
        $c->health = $health;
        $c->updatedAtUtc = $atUtc;
        if (!$health->isHealthy() && $health->consecutiveFailures() >= 3) {
            $c->status = self::STATUS_OFFLINE;
        }
        return $c;
    }

    public function withActiveAssignments(int $n, string $atUtc): self
    {
        $c = clone $this;
        $c->activeAssignments = max(0, $n);
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withMetrics(array $metrics, string $atUtc): self
    {
        $c = clone $this;
        $c->metrics = $metrics;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withSealedHashes(): self
    {
        $c = clone $this;
        $c->compatibility = $this->compatibility !== [] ? $this->compatibility : self::defaultCompatibility();
        $c->reproducibilityFingerprint = 'sha256:' . hash('sha256', json_encode([
            'agentId' => $this->agentId,
            'role' => $this->role,
            'profile' => $this->profile->toArray(),
        ], JSON_THROW_ON_ERROR));
        $payload = $c->toArray();
        unset($payload['integrityHash']);
        $c->integrityHash = 'sha256:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'agentId' => $this->agentId,
            'name' => $this->name,
            'role' => $this->role,
            'status' => $this->status,
            'profile' => $this->profile->toArray(),
            'health' => $this->health->toArray(),
            'metrics' => $this->metrics !== [] ? $this->metrics : [
                'successCount' => 0,
                'failureCount' => 0,
                'reassignmentCount' => 0,
                'avgLatencySeconds' => 0,
                'costUnits' => 0,
            ],
            'activeAssignments' => $this->activeAssignments,
            'availableSlots' => $this->availableSlots(),
            'compatibility' => $this->compatibility !== [] ? $this->compatibility : self::defaultCompatibility(),
            'reproducibilityFingerprint' => $this->reproducibilityFingerprint,
            'integrityHash' => $this->integrityHash,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['agentId'] ?? null) ? $data['agentId'] : '',
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['role'] ?? null) ? $data['role'] : self::ROLE_IMPLEMENTER,
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_REGISTERED,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            isset($data['profile']) && is_array($data['profile']) ? AgentProfile::fromArray($data['profile']) : new AgentProfile(),
            isset($data['health']) && is_array($data['health']) ? AgentHealth::fromArray($data['health']) : new AgentHealth(),
            is_array($data['metrics'] ?? null) ? $data['metrics'] : [],
            is_array($data['compatibility'] ?? null) ? $data['compatibility'] : self::defaultCompatibility(),
            is_string($data['reproducibilityFingerprint'] ?? null) ? $data['reproducibilityFingerprint'] : '',
            is_string($data['integrityHash'] ?? null) ? $data['integrityHash'] : '',
            is_int($data['activeAssignments'] ?? null) ? $data['activeAssignments'] : 0,
            is_int($data['schemaVersion'] ?? null) ? $data['schemaVersion'] : self::SCHEMA_VERSION,
        );
    }

    /** @return array<string, mixed> */
    public static function defaultCompatibility(): array
    {
        return [
            'MissionDomain' => '1',
            'EngineeringExecutionProvider' => '0.3',
            'PlanningScheduling' => '0.7',
            'aepMinVersion' => '0.8.0',
        ];
    }

    public static function makeId(): string
    {
        return 'agt_' . bin2hex(random_bytes(6));
    }
}
