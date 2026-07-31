<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Model;

final class ProgramNode
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_LAUNCHING = 'launching';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @param list<string> $dependsOn
     * @param array<string, mixed> $estimates
     * @param array<string, mixed> $retry
     * @param array<string, mixed> $constraints
     */
    public function __construct(
        private string $nodeId,
        private string $title,
        private string $objective,
        private string $status = self::STATUS_PENDING,
        private array $dependsOn = [],
        private string $priority = 'normal',
        private string $kind = 'mission',
        private ?string $missionId = null,
        private ?string $runId = null,
        private ?string $providerId = null,
        private string $workspacePolicy = 'per_mission',
        private array $estimates = [],
        private array $retry = ['attempt' => 0, 'maxAttempts' => 2, 'backoffSeconds' => 5],
        private string $failurePolicy = 'retry',
        private array $constraints = [],
        private string $edgeTypeDefault = 'succeeds_before',
    ) {
    }

    public function nodeId(): string { return $this->nodeId; }
    public function title(): string { return $this->title; }
    public function objective(): string { return $this->objective; }
    public function status(): string { return $this->status; }
    /** @return list<string> */
    public function dependsOn(): array { return $this->dependsOn; }
    public function priority(): string { return $this->priority; }
    public function kind(): string { return $this->kind; }
    public function missionId(): ?string { return $this->missionId; }
    public function runId(): ?string { return $this->runId; }
    public function providerId(): ?string { return $this->providerId; }
    public function failurePolicy(): string { return $this->failurePolicy; }
    /** @return array<string, mixed> */
    public function estimates(): array { return $this->estimates; }
    /** @return array<string, mixed> */
    public function retry(): array { return $this->retry; }
    /** @return array<string, mixed> */
    public function constraints(): array { return $this->constraints; }

    public function withStatus(string $status): self
    {
        $c = clone $this;
        $c->status = $status;
        return $c;
    }

    public function withLaunch(string $missionId, string $runId, ?string $providerId = null): self
    {
        $c = clone $this;
        $c->missionId = $missionId;
        $c->runId = $runId;
        if ($providerId !== null) {
            $c->providerId = $providerId;
        }
        $c->status = self::STATUS_RUNNING;
        return $c;
    }

    public function withProvider(?string $providerId): self
    {
        $c = clone $this;
        $c->providerId = $providerId;
        return $c;
    }

    public function withRetryAttempt(int $attempt): self
    {
        $c = clone $this;
        $c->retry = array_merge($this->retry, ['attempt' => $attempt]);
        return $c;
    }

    public function withEstimates(array $estimates): self
    {
        $c = clone $this;
        $c->estimates = $estimates;
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'kind' => $this->kind,
            'title' => $this->title,
            'objective' => $this->objective,
            'status' => $this->status,
            'dependsOn' => $this->dependsOn,
            'priority' => $this->priority,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'providerId' => $this->providerId,
            'workspacePolicy' => $this->workspacePolicy,
            'estimates' => $this->estimates !== [] ? $this->estimates : ['durationSeconds' => 300, 'costUnits' => 1.0, 'confidence' => 0.5],
            'retry' => $this->retry,
            'failurePolicy' => $this->failurePolicy,
            'constraints' => $this->constraints,
            'edgeTypeDefault' => $this->edgeTypeDefault,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $depends = [];
        if (isset($data['dependsOn']) && is_array($data['dependsOn'])) {
            foreach ($data['dependsOn'] as $d) {
                if (is_string($d)) {
                    $depends[] = $d;
                }
            }
        }
        return new self(
            is_string($data['nodeId'] ?? null) ? $data['nodeId'] : self::makeId(),
            is_string($data['title'] ?? null) ? $data['title'] : '',
            is_string($data['objective'] ?? null) ? $data['objective'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PENDING,
            $depends,
            is_string($data['priority'] ?? null) ? $data['priority'] : 'normal',
            is_string($data['kind'] ?? null) ? $data['kind'] : 'mission',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_string($data['runId'] ?? null) ? $data['runId'] : null,
            is_string($data['providerId'] ?? null) ? $data['providerId'] : null,
            is_string($data['workspacePolicy'] ?? null) ? $data['workspacePolicy'] : 'per_mission',
            is_array($data['estimates'] ?? null) ? $data['estimates'] : [],
            is_array($data['retry'] ?? null) ? $data['retry'] : ['attempt' => 0, 'maxAttempts' => 2, 'backoffSeconds' => 5],
            is_string($data['failurePolicy'] ?? null) ? $data['failurePolicy'] : 'retry',
            is_array($data['constraints'] ?? null) ? $data['constraints'] : [],
            is_string($data['edgeTypeDefault'] ?? null) ? $data['edgeTypeDefault'] : 'succeeds_before',
        );
    }

    public static function makeId(): string
    {
        return 'pn_' . bin2hex(random_bytes(6));
    }
}
