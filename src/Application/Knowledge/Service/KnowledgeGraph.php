<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Port\KnowledgeStore;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Lightweight adjacency graph over knowledge and plane entities.
 */
final class KnowledgeGraph
{
    public function __construct(private readonly KnowledgeStore $store)
    {
    }

    public function link(
        string $fromType,
        string $fromId,
        string $rel,
        string $toType,
        string $toId,
        array $meta = [],
    ): void {
        $this->store->addEdge([
            'fromType' => $fromType,
            'fromId' => $fromId,
            'rel' => $rel,
            'toType' => $toType,
            'toId' => $toId,
            'meta' => $meta,
            'atUtc' => Utc::now(),
        ]);
    }

    public function linkRecord(KnowledgeRecord $record): void
    {
        $kid = $record->knowledgeId();
        if ($record->missionId()) {
            $this->link('knowledge', $kid, 'derived_from', 'mission', $record->missionId());
        }
        if ($record->patchId()) {
            $this->link('knowledge', $kid, 'derived_from', 'patch', $record->patchId());
        }
        if ($record->workspaceId()) {
            $this->link('knowledge', $kid, 'derived_from', 'workspace', $record->workspaceId());
        }
        if ($record->projectId()) {
            $this->link('knowledge', $kid, 'derived_from', 'project', $record->projectId());
        }
    }

    /** @return list<array<string, mixed>> */
    public function neighbors(string $nodeId, int $limit = 50): array
    {
        return $this->store->edges($nodeId, $limit);
    }
}
