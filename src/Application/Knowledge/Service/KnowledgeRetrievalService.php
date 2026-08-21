<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalPack;
use Aep\Application\Knowledge\Model\RetrievalQuery;
use Aep\Application\Knowledge\Port\EmbeddingProviderRegistry;
use Aep\Application\Knowledge\Port\KnowledgeSettingsStore;
use Aep\Application\Knowledge\Port\KnowledgeStore;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Retrieves knowledge by evaluating configured KnowledgeRetrievalPolicy rules (via KnowledgeRanker).
 */
final class KnowledgeRetrievalService
{
    public function __construct(
        private readonly KnowledgeStore $store,
        private readonly KnowledgeSettingsStore $settings,
        private readonly KnowledgeRanker $ranker,
        private readonly EmbeddingProviderRegistry $embeddings,
        private readonly KnowledgeGraph $graph,
    ) {
    }

    public function retrieve(RetrievalQuery $query): RetrievalPack
    {
        $settings = $this->settings->get();
        if (($settings['autoRetrieveEnabled'] ?? true) !== true && $query->mode() === 'pre_execution') {
            return new RetrievalPack([], [], [], [], [], [], [
                ['policy' => 'settings', 'include' => false, 'scoreDelta' => 0, 'reason' => 'autoRetrieveEnabled=false'],
            ], 'sha256:' . hash('sha256', 'disabled'), Utc::now());
        }

        $candidates = $this->store->list($query->projectId(), null, KnowledgeRecord::STATUS_ACTIVE);
        if ($query->projectId() === null || $query->projectId() === '') {
            $candidates = $this->store->list(null, null, KnowledgeRecord::STATUS_ACTIVE);
        }

        $providerId = is_string($settings['embeddingProviderId'] ?? null)
            ? $settings['embeddingProviderId']
            : 'local_lexical';
        $provider = $this->embeddings->get($providerId) ?? $this->embeddings->defaultProvider();
        $similarity = [];
        try {
            $qVec = $provider->embed([$query->objective()])[0] ?? [];
            foreach ($candidates as $record) {
                $cVec = $this->store->loadEmbedding($provider->id(), $record->knowledgeId());
                if ($cVec === null) {
                    $cVec = $provider->embed([$record->title() . "\n" . $record->summary()])[0] ?? [];
                }
                if ($qVec !== [] && $cVec !== []) {
                    $similarity[$record->knowledgeId()] = $provider->similarity($qVec, $cVec);
                }
            }
        } catch (\Throwable) {
            $similarity = [];
        }

        $ranked = $this->ranker->rank($query, $candidates, [
            'similarity' => $similarity,
            'nowUtc' => Utc::now(),
            'settings' => $settings,
        ]);

        $limit = $query->limit();
        if (is_int($settings['maxNotes'] ?? null)) {
            $limit = min($limit, max(1, (int) $settings['maxNotes']));
        }
        $ranked = array_slice($ranked, 0, $limit);

        $notes = [];
        $refs = [];
        $hits = [];
        $lessons = [];
        $similarMissions = [];
        $similarPatches = [];
        $policyTrace = [];
        $seenStable = [];

        foreach ($ranked as $row) {
            /** @var KnowledgeRecord $record */
            $record = $row['record'];
            if (isset($seenStable[$record->stableKey()])) {
                continue;
            }
            $seenStable[$record->stableKey()] = true;
            $note = '[' . $record->kind() . '] ' . $record->summary();
            $notes[] = $note;
            $refs[] = 'knowledge:' . $record->knowledgeId();
            if ($record->missionId()) {
                $refs[] = 'mission:' . $record->missionId();
            }
            $hits[] = [
                'knowledgeId' => $record->knowledgeId(),
                'kind' => $record->kind(),
                'title' => $record->title(),
                'summary' => $record->summary(),
                'score' => $row['score'],
                'missionId' => $record->missionId(),
                'patchId' => $record->patchId(),
                'trace' => $row['trace'],
            ];
            $policyTrace = array_merge($policyTrace, $row['trace']);
            if (in_array($record->kind(), [KnowledgeRecord::KIND_LESSON, KnowledgeRecord::KIND_SUCCESS_PATTERN, KnowledgeRecord::KIND_FAILURE], true)) {
                $lessons[] = [
                    'knowledgeId' => $record->knowledgeId(),
                    'kind' => $record->kind(),
                    'title' => $record->title(),
                    'summary' => $record->summary(),
                    'score' => $row['score'],
                ];
            }
            if ($record->missionId() && $record->kind() === KnowledgeRecord::KIND_MISSION) {
                $similarMissions[] = [
                    'missionId' => $record->missionId(),
                    'knowledgeId' => $record->knowledgeId(),
                    'title' => $record->title(),
                    'score' => $row['score'],
                ];
            }
            if ($record->patchId() && in_array($record->kind(), [KnowledgeRecord::KIND_PATCH, KnowledgeRecord::KIND_REVIEW], true)) {
                $similarPatches[] = [
                    'patchId' => $record->patchId(),
                    'knowledgeId' => $record->knowledgeId(),
                    'title' => $record->title(),
                    'score' => $row['score'],
                ];
            }

            $reinforced = $record->withUsefulness(
                min(1.0, $record->usefulness() + 0.02),
                $record->hitCount() + 1,
                Utc::now()
            )->withSealedHashes();
            $this->store->save($reinforced);
        }

        $fingerprint = 'sha256:' . hash('sha256', json_encode([
            'notes' => $notes,
            'refs' => array_values(array_unique($refs)),
            'objective' => $query->objective(),
        ], JSON_THROW_ON_ERROR));

        $this->store->appendTimeline([
            'atUtc' => Utc::now(),
            'type' => 'retrieved',
            'message' => 'Retrieval pack for mode=' . $query->mode(),
            'hitCount' => count($hits),
            'fingerprint' => $fingerprint,
        ]);

        return new RetrievalPack(
            $notes,
            array_values(array_unique($refs)),
            $hits,
            $similarMissions,
            $similarPatches,
            $lessons,
            $policyTrace,
            $fingerprint,
            Utc::now(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function similarMissions(string $objective, ?string $projectId = null, int $limit = 10): array
    {
        $pack = $this->retrieve(new RetrievalQuery(
            $objective,
            $projectId,
            null,
            [],
            [],
            [KnowledgeRecord::KIND_MISSION, KnowledgeRecord::KIND_SUCCESS_PATTERN],
            [],
            $limit,
            'similar_missions',
        ));

        return $pack->toArray()['similarMissions'];
    }

    /** @return list<array<string, mixed>> */
    public function similarPatches(string $objective, ?string $projectId = null, int $limit = 10): array
    {
        $pack = $this->retrieve(new RetrievalQuery(
            $objective,
            $projectId,
            null,
            [],
            [],
            [KnowledgeRecord::KIND_PATCH, KnowledgeRecord::KIND_REVIEW],
            [],
            $limit,
            'similar_patches',
        ));

        return $pack->toArray()['similarPatches'];
    }
}
