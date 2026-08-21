<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Service;

use Aep\Application\Governance\Model\AuditEntry;
use Aep\Application\Governance\Port\GovernanceStore;
use Aep\Application\MissionControl\Support\Utc;

final class AuditManager
{
    public function __construct(private readonly GovernanceStore $store) {}

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param list<string> $evidenceRefs
     * @param array<string, mixed> $correlationIds
     */
    public function record(
        string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before = null,
        ?array $after = null,
        array $evidenceRefs = [],
        array $correlationIds = [],
    ): AuditEntry {
        $entry = new AuditEntry(
            AuditEntry::makeId(),
            Utc::now(),
            $actorId,
            $action,
            $subjectType,
            $subjectId,
            $before,
            $after,
            $evidenceRefs,
            $correlationIds,
        );
        $this->store->appendAudit($entry);
        return $entry;
    }

    /** @return list<array<string, mixed>> */
    public function trail(int $limit = 200): array
    {
        return array_map(static fn (AuditEntry $e) => $e->toArray(), $this->store->audit($limit));
    }
}
