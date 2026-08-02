<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class ChangeRequest
{
    public const STATUS_OPEN = 'open';
    public const STATUS_LINKED = 'linked';
    public const STATUS_CLOSED = 'closed';

    /**
     * @param list<string> $patchIds
     * @param list<string> $missionIds
     * @param list<string> $artifactIds
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $changeRequestId,
        private string $title,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private string $requestedBy = 'system',
        private array $patchIds = [],
        private array $missionIds = [],
        private array $artifactIds = [],
        private ?string $programId = null,
        private array $meta = [],
    ) {}

    public function changeRequestId(): string { return $this->changeRequestId; }
    public function status(): string { return $this->status; }
    /** @return list<string> */
    public function patchIds(): array { return $this->patchIds; }

    public function withStatus(string $status, string $atUtc): self
    {
        $c = clone $this; $c->status = $status; $c->updatedAtUtc = $atUtc; return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'changeRequestId' => $this->changeRequestId,
            'title' => $this->title,
            'status' => $this->status,
            'requestedBy' => $this->requestedBy,
            'patchIds' => $this->patchIds,
            'missionIds' => $this->missionIds,
            'artifactIds' => $this->artifactIds,
            'programId' => $this->programId,
            'meta' => $this->meta,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $patches = [];
        foreach (is_array($data['patchIds'] ?? null) ? $data['patchIds'] : [] as $p) {
            if (is_string($p)) { $patches[] = $p; }
        }
        $missions = [];
        foreach (is_array($data['missionIds'] ?? null) ? $data['missionIds'] : [] as $m) {
            if (is_string($m)) { $missions[] = $m; }
        }
        $arts = [];
        foreach (is_array($data['artifactIds'] ?? null) ? $data['artifactIds'] : [] as $a) {
            if (is_string($a)) { $arts[] = $a; }
        }
        return new self(
            is_string($data['changeRequestId'] ?? null) ? $data['changeRequestId'] : self::makeId(),
            is_string($data['title'] ?? null) ? $data['title'] : 'Change',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_OPEN,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['requestedBy'] ?? null) ? $data['requestedBy'] : 'system',
            $patches, $missions, $arts,
            is_string($data['programId'] ?? null) ? $data['programId'] : null,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    public static function makeId(): string { return 'cr_' . bin2hex(random_bytes(6)); }
}
