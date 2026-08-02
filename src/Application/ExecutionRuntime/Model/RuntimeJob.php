<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Model;

/**
 * Generic Runtime job — type-agnostic unit of asynchronous work.
 */
final class RuntimeJob
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        private string $id,
        private string $type,
        private string $status,
        private array $payload,
        private int $attempts,
        private int $maxAttempts,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $leaseOwner = null,
        private ?string $leaseExpiresAtUtc = null,
        private ?string $lastHeartbeatAtUtc = null,
        private ?array $result = null,
        private ?string $error = null,
        private bool $cancelRequested = false,
        private ?string $cancelReason = null,
        private int $claimGeneration = 0,
        private bool $executed = false,
    ) {
        $this->id = trim($id);
        $this->type = trim($type);
        if ($this->id === '' || $this->type === '') {
            throw new \InvalidArgumentException('job id and type are required.');
        }
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    public function leaseOwner(): ?string
    {
        return $this->leaseOwner;
    }

    public function leaseExpiresAtUtc(): ?string
    {
        return $this->leaseExpiresAtUtc;
    }

    public function lastHeartbeatAtUtc(): ?string
    {
        return $this->lastHeartbeatAtUtc;
    }

    /** @return array<string, mixed>|null */
    public function result(): ?array
    {
        return $this->result;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function cancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function claimGeneration(): int
    {
        return $this->claimGeneration;
    }

    public function executed(): bool
    {
        return $this->executed;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'leaseOwner' => $this->leaseOwner,
            'leaseExpiresAtUtc' => $this->leaseExpiresAtUtc,
            'lastHeartbeatAtUtc' => $this->lastHeartbeatAtUtc,
            'result' => $this->result,
            'error' => $this->error,
            'cancelRequested' => $this->cancelRequested,
            'cancelReason' => $this->cancelReason,
            'claimGeneration' => $this->claimGeneration,
            'executed' => $this->executed,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_QUEUED,
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            is_int($data['attempts'] ?? null) ? $data['attempts'] : 0,
            is_int($data['maxAttempts'] ?? null) ? $data['maxAttempts'] : 3,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['leaseOwner'] ?? null) ? $data['leaseOwner'] : null,
            is_string($data['leaseExpiresAtUtc'] ?? null) ? $data['leaseExpiresAtUtc'] : null,
            is_string($data['lastHeartbeatAtUtc'] ?? null) ? $data['lastHeartbeatAtUtc'] : null,
            is_array($data['result'] ?? null) ? $data['result'] : null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            ($data['cancelRequested'] ?? false) === true,
            is_string($data['cancelReason'] ?? null) ? $data['cancelReason'] : null,
            is_int($data['claimGeneration'] ?? null) ? $data['claimGeneration'] : 0,
            ($data['executed'] ?? false) === true,
        );
    }
}
