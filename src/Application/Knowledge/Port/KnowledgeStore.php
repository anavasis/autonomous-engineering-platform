<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Port;

use Aep\Application\Knowledge\Model\KnowledgeRecord;

interface KnowledgeStore
{
    public function save(KnowledgeRecord $record): void;

    public function find(string $knowledgeId): ?KnowledgeRecord;

    public function findByStableKey(string $stableKey): ?KnowledgeRecord;

    /** @return list<KnowledgeRecord> */
    public function list(?string $projectId = null, ?string $kind = null, ?string $status = null, ?string $q = null): array;

    /** @return list<KnowledgeRecord> */
    public function listByMission(string $missionId): array;

    /** @return list<array<string, mixed>> */
    public function timeline(?string $knowledgeId = null, int $limit = 100): array;

    /** @param array<string, mixed> $event */
    public function appendTimeline(array $event): void;

    /** @param array<string, mixed> $edge */
    public function addEdge(array $edge): void;

    /** @return list<array<string, mixed>> */
    public function edges(?string $nodeId = null, int $limit = 200): array;

    public function saveEmbedding(string $providerId, string $knowledgeId, array $vector): void;

    /** @return list<float>|null */
    public function loadEmbedding(string $providerId, string $knowledgeId): ?array;
}
