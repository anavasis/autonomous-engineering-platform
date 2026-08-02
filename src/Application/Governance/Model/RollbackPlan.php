<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class RollbackPlan
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_STARTED = 'started';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** @param list<string> $checklist @param array<string, mixed> $meta */
    public function __construct(
        private string $rollbackId,
        private string $releaseId,
        private string $toReleaseId,
        private string $environmentId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private array $checklist = [],
        private array $meta = [],
        private string $message = '',
    ) {}

    public function rollbackId(): string { return $this->rollbackId; }
    public function releaseId(): string { return $this->releaseId; }
    public function toReleaseId(): string { return $this->toReleaseId; }
    public function environmentId(): string { return $this->environmentId; }
    public function status(): string { return $this->status; }

    public function withStatus(string $status, string $atUtc, string $message = ''): self
    {
        $c = clone $this; $c->status = $status; $c->updatedAtUtc = $atUtc; $c->message = $message; return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rollbackId' => $this->rollbackId,
            'releaseId' => $this->releaseId,
            'toReleaseId' => $this->toReleaseId,
            'environmentId' => $this->environmentId,
            'status' => $this->status,
            'checklist' => $this->checklist,
            'meta' => $this->meta,
            'message' => $this->message,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $checklist = [];
        foreach (is_array($data['checklist'] ?? null) ? $data['checklist'] : [] as $c) {
            if (is_string($c)) { $checklist[] = $c; }
        }
        return new self(
            is_string($data['rollbackId'] ?? null) ? $data['rollbackId'] : self::makeId(),
            is_string($data['releaseId'] ?? null) ? $data['releaseId'] : '',
            is_string($data['toReleaseId'] ?? null) ? $data['toReleaseId'] : '',
            is_string($data['environmentId'] ?? null) ? $data['environmentId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PLANNED,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            $checklist,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
            is_string($data['message'] ?? null) ? $data['message'] : '',
        );
    }

    public static function makeId(): string { return 'rb_' . bin2hex(random_bytes(6)); }
}
