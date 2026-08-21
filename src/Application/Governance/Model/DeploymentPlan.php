<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class DeploymentPlan
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_STARTED = 'started';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $deploymentId,
        private string $releaseId,
        private string $environmentId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $targetId = null,
        private array $steps = [],
        private array $meta = [],
        private string $message = '',
    ) {}

    public function deploymentId(): string { return $this->deploymentId; }
    public function releaseId(): string { return $this->releaseId; }
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
            'deploymentId' => $this->deploymentId,
            'releaseId' => $this->releaseId,
            'environmentId' => $this->environmentId,
            'targetId' => $this->targetId,
            'status' => $this->status,
            'steps' => $this->steps,
            'meta' => $this->meta,
            'message' => $this->message,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $steps = [];
        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $s) {
            if (is_array($s)) { $steps[] = $s; }
        }
        return new self(
            is_string($data['deploymentId'] ?? null) ? $data['deploymentId'] : self::makeId(),
            is_string($data['releaseId'] ?? null) ? $data['releaseId'] : '',
            is_string($data['environmentId'] ?? null) ? $data['environmentId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_PLANNED,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['targetId'] ?? null) ? $data['targetId'] : null,
            $steps,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
            is_string($data['message'] ?? null) ? $data['message'] : '',
        );
    }

    public static function makeId(): string { return 'dep_' . bin2hex(random_bytes(6)); }
}
