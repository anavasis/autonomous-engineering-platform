<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class ReleaseRecord
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_DEPLOYED = 'deployed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @param list<string> $changeRequestIds
     * @param list<string> $patchIds
     * @param list<string> $missionIds
     * @param list<string> $artifactIds
     * @param list<array<string, mixed>> $gates
     * @param list<array<string, mixed>> $approvals
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $releaseId,
        private string $version,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private string $title = 'Release',
        private array $changeRequestIds = [],
        private array $patchIds = [],
        private array $missionIds = [],
        private array $artifactIds = [],
        private array $gates = [],
        private array $approvals = [],
        private ?string $currentEnvironmentId = null,
        private ?string $scheduledAtUtc = null,
        private string $integrityHash = '',
        private array $meta = [],
    ) {}

    public function releaseId(): string { return $this->releaseId; }
    public function version(): string { return $this->version; }
    public function status(): string { return $this->status; }
    public function title(): string { return $this->title; }
    /** @return list<string> */
    public function patchIds(): array { return $this->patchIds; }
    /** @return list<array<string, mixed>> */
    public function gates(): array { return $this->gates; }
    /** @return list<array<string, mixed>> */
    public function approvals(): array { return $this->approvals; }
    public function currentEnvironmentId(): ?string { return $this->currentEnvironmentId; }
    public function integrityHash(): string { return $this->integrityHash; }

    public function withStatus(string $status, string $atUtc): self
    {
        $c = clone $this; $c->status = $status; $c->updatedAtUtc = $atUtc; return $c;
    }

    /** @param list<array<string, mixed>> $gates */
    public function withGates(array $gates, string $atUtc): self
    {
        $c = clone $this; $c->gates = $gates; $c->updatedAtUtc = $atUtc; return $c;
    }

    /** @param list<array<string, mixed>> $approvals */
    public function withApprovals(array $approvals, string $atUtc): self
    {
        $c = clone $this; $c->approvals = $approvals; $c->updatedAtUtc = $atUtc; return $c;
    }

    public function withEnvironment(?string $environmentId, string $atUtc): self
    {
        $c = clone $this; $c->currentEnvironmentId = $environmentId; $c->updatedAtUtc = $atUtc; return $c;
    }

    public function withSchedule(?string $scheduledAtUtc, string $atUtc): self
    {
        $c = clone $this; $c->scheduledAtUtc = $scheduledAtUtc; $c->updatedAtUtc = $atUtc; return $c;
    }

    public function withSealedHash(): self
    {
        $c = clone $this;
        $payload = $c->toArray();
        unset($payload['integrityHash']);
        $c->integrityHash = 'sha256:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $c;
    }

    public function allGatesPassed(): bool
    {
        if ($this->gates === []) { return false; }
        foreach ($this->gates as $g) {
            $st = is_string($g['status'] ?? null) ? $g['status'] : QualityGate::STATUS_PENDING;
            if (!in_array($st, [QualityGate::STATUS_PASSED, QualityGate::STATUS_SKIPPED], true)) {
                return false;
            }
        }
        return true;
    }

    public function approvalsSatisfied(): bool
    {
        if ($this->approvals === []) { return false; }
        foreach ($this->approvals as $a) {
            if (($a['status'] ?? '') !== 'granted') { return false; }
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'releaseId' => $this->releaseId,
            'version' => $this->version,
            'title' => $this->title,
            'status' => $this->status,
            'changeRequestIds' => $this->changeRequestIds,
            'patchIds' => $this->patchIds,
            'missionIds' => $this->missionIds,
            'artifactIds' => $this->artifactIds,
            'gates' => $this->gates,
            'approvals' => $this->approvals,
            'currentEnvironmentId' => $this->currentEnvironmentId,
            'scheduledAtUtc' => $this->scheduledAtUtc,
            'integrityHash' => $this->integrityHash,
            'meta' => $this->meta,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $strList = static function (mixed $v): array {
            $out = [];
            if (!is_array($v)) { return $out; }
            foreach ($v as $i) { if (is_string($i)) { $out[] = $i; } }
            return $out;
        };
        $arrList = static function (mixed $v): array {
            $out = [];
            if (!is_array($v)) { return $out; }
            foreach ($v as $i) { if (is_array($i)) { $out[] = $i; } }
            return $out;
        };
        return new self(
            is_string($data['releaseId'] ?? null) ? $data['releaseId'] : self::makeId(),
            is_string($data['version'] ?? null) ? $data['version'] : '0.0.0',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_DRAFT,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['title'] ?? null) ? $data['title'] : 'Release',
            $strList($data['changeRequestIds'] ?? []),
            $strList($data['patchIds'] ?? []),
            $strList($data['missionIds'] ?? []),
            $strList($data['artifactIds'] ?? []),
            $arrList($data['gates'] ?? []),
            $arrList($data['approvals'] ?? []),
            is_string($data['currentEnvironmentId'] ?? null) ? $data['currentEnvironmentId'] : null,
            is_string($data['scheduledAtUtc'] ?? null) ? $data['scheduledAtUtc'] : null,
            is_string($data['integrityHash'] ?? null) ? $data['integrityHash'] : '',
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public static function makeId(): string { return 'rel_' . bin2hex(random_bytes(6)); }
}
