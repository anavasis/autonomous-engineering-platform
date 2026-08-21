<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;
use Aep\Application\Knowledge\Port\EmbeddingProviderRegistry;
use Aep\Application\Knowledge\Port\KnowledgeSettingsStore;
use Aep\Application\Knowledge\Port\KnowledgeStore;

final class KnowledgeQueryService
{
    public function __construct(
        private readonly KnowledgeStore $store,
        private readonly KnowledgeSettingsStore $settings,
        private readonly KnowledgeCaptureService $capture,
        private readonly KnowledgeRetrievalService $retrieval,
        private readonly KnowledgeGraph $graph,
        private readonly EmbeddingProviderRegistry $embeddings,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $projectId = null, ?string $kind = null, ?string $status = null, ?string $q = null): array
    {
        return array_map(
            static fn (KnowledgeRecord $r) => self::summary($r),
            $this->store->list($projectId, $kind, $status, $q)
        );
    }

    /** @return array<string, mixed>|null */
    public function get(string $knowledgeId): ?array
    {
        $record = $this->store->find($knowledgeId);
        if ($record === null) {
            return null;
        }
        $data = $record->toArray();
        $data['timeline'] = $this->store->timeline($knowledgeId);
        $data['neighbors'] = $this->graph->neighbors($knowledgeId);

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function missionMemory(string $missionId): array
    {
        return array_map(
            static fn (KnowledgeRecord $r) => self::summary($r),
            $this->store->listByMission($missionId)
        );
    }

    /** @return list<array<string, mixed>> */
    public function lessons(?string $projectId = null): array
    {
        $out = [];
        foreach ([
            KnowledgeRecord::KIND_LESSON,
            KnowledgeRecord::KIND_SUCCESS_PATTERN,
            KnowledgeRecord::KIND_FAILURE,
            KnowledgeRecord::KIND_CONSTRAINT,
        ] as $kind) {
            foreach ($this->store->list($projectId, $kind, KnowledgeRecord::STATUS_ACTIVE) as $r) {
                $out[] = self::summary($r);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function timeline(?string $knowledgeId = null, int $limit = 100): array
    {
        return $this->store->timeline($knowledgeId, $limit);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function retrievePreview(array $body): array
    {
        $query = RetrievalQuery::fromArray($body);
        if ($query->mode() === 'pre_execution') {
            $query = RetrievalQuery::fromArray(array_merge($body, ['mode' => 'preview']));
        }

        return $this->retrieval->retrieve($query)->toArray();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function captureManual(array $body, string $capturedBy = 'operator'): array
    {
        return $this->capture->capture($body, $capturedBy)->toArray();
    }

    /** @return array<string, mixed>|null */
    public function archive(string $knowledgeId): ?array
    {
        return $this->capture->archive($knowledgeId)?->toArray();
    }

    /** @return array<string, mixed>|null */
    public function forget(string $knowledgeId): ?array
    {
        return $this->capture->forget($knowledgeId)?->toArray();
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings->get();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array
    {
        return $this->settings->put($settings);
    }

    /** @return list<array<string, mixed>> */
    public function listEmbeddingProviders(): array
    {
        return $this->embeddings->list();
    }

    public function capture(): KnowledgeCaptureService
    {
        return $this->capture;
    }

    public function retrieval(): KnowledgeRetrievalService
    {
        return $this->retrieval;
    }

    /** @return array<string, mixed> */
    private static function summary(KnowledgeRecord $r): array
    {
        $data = $r->toArray();

        return [
            'knowledgeId' => $data['knowledgeId'],
            'kind' => $data['kind'],
            'status' => $data['status'],
            'title' => $data['title'],
            'summary' => $data['summary'],
            'projectId' => $data['projectId'],
            'missionId' => $data['missionId'],
            'patchId' => $data['patchId'],
            'workspaceId' => $data['workspaceId'],
            'usefulness' => $data['usefulness'],
            'confidence' => $data['confidence'],
            'reproducibilityFingerprint' => $data['reproducibilityFingerprint'],
            'integrityHash' => $data['integrityHash'],
            'schemaVersion' => $data['schemaVersion'],
            'capturedBy' => $data['capturedBy'],
            'captureVersion' => $data['captureVersion'],
            'updatedAtUtc' => $data['updatedAtUtc'],
        ];
    }
}
