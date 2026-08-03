<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Model;

/**
 * Runtime-level job lifecycle event (distinct from ProviderEvents).
 */
final class RuntimeEvent
{
    public const JOB_QUEUED = 'JobQueued';
    public const JOB_CLAIMED = 'JobClaimed';
    public const LEASE_RENEWED = 'LeaseRenewed';
    public const HEARTBEAT = 'Heartbeat';
    public const RETRY_SCHEDULED = 'RetryScheduled';
    public const COMPLETED = 'Completed';
    public const FAILED = 'Failed';
    public const CANCELLED = 'Cancelled';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $id,
        private string $jobId,
        private string $type,
        private string $at,
        private array $data = [],
    ) {
        $this->id = trim($id);
        $this->jobId = trim($jobId);
        $this->type = trim($type);
        if ($this->id === '' || $this->jobId === '' || $this->type === '') {
            throw new \InvalidArgumentException('RuntimeEvent id, jobId, and type are required.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function at(): string
    {
        return $this->at;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'jobId' => $this->jobId,
            'type' => $this->type,
            'at' => $this->at,
            'data' => $this->data,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            is_string($row['id'] ?? null) ? $row['id'] : '',
            is_string($row['jobId'] ?? null) ? $row['jobId'] : '',
            is_string($row['type'] ?? null) ? $row['type'] : '',
            is_string($row['at'] ?? null) ? $row['at'] : '',
            is_array($row['data'] ?? null) ? $row['data'] : [],
        );
    }

    /** @param array<string, mixed> $data */
    public static function create(string $jobId, string $type, string $at, array $data = []): self
    {
        return new self('rte_' . bin2hex(random_bytes(8)), $jobId, $type, $at, $data);
    }
}
