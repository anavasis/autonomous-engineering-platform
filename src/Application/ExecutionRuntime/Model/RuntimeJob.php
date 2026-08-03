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
     * @param array<string, mixed> $metadata arbitrary key/value pairs
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        private string $id,
        private string $type,
        private string $status,
        private string $priority,
        private array $payload,
        private array $metadata,
        private int $attempts,
        private int $maxAttempts,
        private string $createdAt,
        private string $updatedAt,
        private ?string $leaseOwner = null,
        private ?string $leaseExpiresAt = null,
        private ?string $lastHeartbeatAt = null,
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
        $this->priority = JobPriority::normalize($priority);
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

    public function priority(): string
    {
        return $this->priority;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /** @deprecated use createdAt() */
    public function createdAtUtc(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    /** @deprecated use updatedAt() */
    public function updatedAtUtc(): string
    {
        return $this->updatedAt;
    }

    public function leaseOwner(): ?string
    {
        return $this->leaseOwner;
    }

    public function leaseExpiresAt(): ?string
    {
        return $this->leaseExpiresAt;
    }

    /** @deprecated use leaseExpiresAt() */
    public function leaseExpiresAtUtc(): ?string
    {
        return $this->leaseExpiresAt;
    }

    public function lastHeartbeatAt(): ?string
    {
        return $this->lastHeartbeatAt;
    }

    /** @deprecated use lastHeartbeatAt() */
    public function lastHeartbeatAtUtc(): ?string
    {
        return $this->lastHeartbeatAt;
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

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(
            array_key_exists('id', $changes) ? (string) $changes['id'] : $this->id,
            array_key_exists('type', $changes) ? (string) $changes['type'] : $this->type,
            array_key_exists('status', $changes) ? (string) $changes['status'] : $this->status,
            array_key_exists('priority', $changes) ? (string) $changes['priority'] : $this->priority,
            array_key_exists('payload', $changes) && is_array($changes['payload']) ? $changes['payload'] : $this->payload,
            array_key_exists('metadata', $changes) && is_array($changes['metadata']) ? $changes['metadata'] : $this->metadata,
            array_key_exists('attempts', $changes) ? (int) $changes['attempts'] : $this->attempts,
            array_key_exists('maxAttempts', $changes) ? (int) $changes['maxAttempts'] : $this->maxAttempts,
            array_key_exists('createdAt', $changes) ? (string) $changes['createdAt'] : $this->createdAt,
            array_key_exists('updatedAt', $changes) ? (string) $changes['updatedAt'] : $this->updatedAt,
            array_key_exists('leaseOwner', $changes)
                ? ($changes['leaseOwner'] !== null ? (string) $changes['leaseOwner'] : null)
                : $this->leaseOwner,
            array_key_exists('leaseExpiresAt', $changes)
                ? ($changes['leaseExpiresAt'] !== null ? (string) $changes['leaseExpiresAt'] : null)
                : $this->leaseExpiresAt,
            array_key_exists('lastHeartbeatAt', $changes)
                ? ($changes['lastHeartbeatAt'] !== null ? (string) $changes['lastHeartbeatAt'] : null)
                : $this->lastHeartbeatAt,
            array_key_exists('result', $changes)
                ? (is_array($changes['result']) || $changes['result'] === null ? $changes['result'] : $this->result)
                : $this->result,
            array_key_exists('error', $changes)
                ? ($changes['error'] !== null ? (string) $changes['error'] : null)
                : $this->error,
            array_key_exists('cancelRequested', $changes) ? (bool) $changes['cancelRequested'] : $this->cancelRequested,
            array_key_exists('cancelReason', $changes)
                ? ($changes['cancelReason'] !== null ? (string) $changes['cancelReason'] : null)
                : $this->cancelReason,
            array_key_exists('claimGeneration', $changes) ? (int) $changes['claimGeneration'] : $this->claimGeneration,
            array_key_exists('executed', $changes) ? (bool) $changes['executed'] : $this->executed,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'leaseOwner' => $this->leaseOwner,
            'leaseExpiresAt' => $this->leaseExpiresAt,
            'cancelRequested' => $this->cancelRequested,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            // Internal / recovery fields
            'lastHeartbeatAt' => $this->lastHeartbeatAt,
            'result' => $this->result,
            'error' => $this->error,
            'cancelReason' => $this->cancelReason,
            'claimGeneration' => $this->claimGeneration,
            'executed' => $this->executed,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $priority = is_string($data['priority'] ?? null) ? $data['priority'] : JobPriority::NORMAL;
        try {
            $priority = JobPriority::normalize($priority);
        } catch (\InvalidArgumentException) {
            $priority = JobPriority::NORMAL;
        }

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['type'] ?? null) ? $data['type'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_QUEUED,
            $priority,
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            is_int($data['attempts'] ?? null) ? $data['attempts'] : 0,
            is_int($data['maxAttempts'] ?? null) ? $data['maxAttempts'] : 3,
            self::stringField($data, ['createdAt', 'createdAtUtc']),
            self::stringField($data, ['updatedAt', 'updatedAtUtc']),
            is_string($data['leaseOwner'] ?? null) ? $data['leaseOwner'] : null,
            self::nullableStringField($data, ['leaseExpiresAt', 'leaseExpiresAtUtc']),
            self::nullableStringField($data, ['lastHeartbeatAt', 'lastHeartbeatAtUtc']),
            is_array($data['result'] ?? null) ? $data['result'] : null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            ($data['cancelRequested'] ?? false) === true,
            is_string($data['cancelReason'] ?? null) ? $data['cancelReason'] : null,
            is_int($data['claimGeneration'] ?? null) ? $data['claimGeneration'] : 0,
            ($data['executed'] ?? false) === true,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function stringField(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function nullableStringField(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return null;
    }
}
