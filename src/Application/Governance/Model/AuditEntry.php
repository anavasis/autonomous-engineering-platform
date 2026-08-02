<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

final class AuditEntry
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param list<string> $evidenceRefs
     * @param array<string, mixed> $correlationIds
     */
    public function __construct(
        private string $entryId,
        private string $atUtc,
        private string $actorId,
        private string $action,
        private string $subjectType,
        private string $subjectId,
        private ?array $before = null,
        private ?array $after = null,
        private array $evidenceRefs = [],
        private array $correlationIds = [],
    ) {}

    public function entryId(): string { return $this->entryId; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entryId' => $this->entryId,
            'atUtc' => $this->atUtc,
            'actorId' => $this->actorId,
            'action' => $this->action,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'before' => $this->before,
            'after' => $this->after,
            'evidenceRefs' => $this->evidenceRefs,
            'correlationIds' => $this->correlationIds,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $refs = [];
        foreach (is_array($data['evidenceRefs'] ?? null) ? $data['evidenceRefs'] : [] as $r) {
            if (is_string($r)) { $refs[] = $r; }
        }
        return new self(
            is_string($data['entryId'] ?? null) ? $data['entryId'] : self::makeId(),
            is_string($data['atUtc'] ?? null) ? $data['atUtc'] : '',
            is_string($data['actorId'] ?? null) ? $data['actorId'] : 'system',
            is_string($data['action'] ?? null) ? $data['action'] : '',
            is_string($data['subjectType'] ?? null) ? $data['subjectType'] : '',
            is_string($data['subjectId'] ?? null) ? $data['subjectId'] : '',
            is_array($data['before'] ?? null) ? $data['before'] : null,
            is_array($data['after'] ?? null) ? $data['after'] : null,
            $refs,
            is_array($data['correlationIds'] ?? null) ? $data['correlationIds'] : [],
        );
    }

    public static function makeId(): string { return 'aud_' . bin2hex(random_bytes(8)); }
}
