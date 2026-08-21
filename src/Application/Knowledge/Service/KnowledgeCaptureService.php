<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Service;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;
use Aep\Application\Knowledge\Port\EmbeddingProviderRegistry;
use Aep\Application\Knowledge\Port\KnowledgeSettingsStore;
use Aep\Application\Knowledge\Port\KnowledgeStore;
use Aep\Application\MissionControl\Support\Utc;

final class KnowledgeCaptureService
{
    public const CAPTURE_VERSION = '0.6.0';

    public function __construct(
        private readonly KnowledgeStore $store,
        private readonly KnowledgeSettingsStore $settings,
        private readonly KnowledgeGraph $graph,
        private readonly EmbeddingProviderRegistry $embeddings,
        private readonly ArchivePolicy $archivePolicy,
        private readonly ForgetPolicy $forgetPolicy,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function capture(array $input, string $capturedBy = 'system'): KnowledgeRecord
    {
        $at = Utc::now();
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : KnowledgeRecord::KIND_ENGINEERING;
        $title = trim(is_string($input['title'] ?? null) ? $input['title'] : 'Knowledge');
        $summary = trim(is_string($input['summary'] ?? null) ? $input['summary'] : $title);
        $body = trim(is_string($input['body'] ?? null) ? $input['body'] : $summary);
        if ($this->looksSensitive($body) || $this->looksSensitive($summary)) {
            $status = KnowledgeRecord::STATUS_QUARANTINED;
            $body = '[quarantined]';
            $summary = '[quarantined]';
        } else {
            $status = KnowledgeRecord::STATUS_ACTIVE;
        }

        $sourceEvents = is_array($input['sourceEvents'] ?? null) ? array_values($input['sourceEvents']) : [];
        $sourceArtifacts = [];
        if (isset($input['sourceArtifacts']) && is_array($input['sourceArtifacts'])) {
            foreach ($input['sourceArtifacts'] as $a) {
                if (is_string($a) && $a !== '') {
                    $sourceArtifacts[] = $a;
                }
            }
        }
        $sourceFp = KnowledgeRecord::computeFingerprint([
            'kind' => $kind,
            'title' => $title,
            'events' => $sourceEvents,
            'artifacts' => $sourceArtifacts,
        ]);
        $stableKey = KnowledgeRecord::makeStableKey($kind, $title, $sourceFp);

        $existing = $this->store->findByStableKey($stableKey);
        if ($existing !== null && $existing->status() === KnowledgeRecord::STATUS_ACTIVE) {
            $superseded = $existing->withStatus(KnowledgeRecord::STATUS_SUPERSEDED, $at)->withSealedHashes();
            $this->store->save($superseded);
            $this->store->appendTimeline([
                'atUtc' => $at,
                'knowledgeId' => $existing->knowledgeId(),
                'type' => 'superseded',
                'message' => 'Superseded by newer capture',
            ]);
        }

        $tokens = RetrievalQuery::tokenize($title . ' ' . $summary . ' ' . $body);
        $paths = [];
        if (isset($input['paths']) && is_array($input['paths'])) {
            foreach ($input['paths'] as $p) {
                if (is_string($p) && $p !== '') {
                    $paths[] = $p;
                }
            }
        }
        $tags = [];
        if (isset($input['tags']) && is_array($input['tags'])) {
            foreach ($input['tags'] as $t) {
                if (is_string($t) && $t !== '') {
                    $tags[] = $t;
                }
            }
        }

        $record = new KnowledgeRecord(
            KnowledgeRecord::makeId(),
            $stableKey,
            $kind,
            $status,
            is_string($input['scope'] ?? null) ? $input['scope'] : 'project',
            $title,
            $summary,
            $body,
            $at,
            $at,
            is_string($input['projectId'] ?? null) ? $input['projectId'] : null,
            is_string($input['missionId'] ?? null) ? $input['missionId'] : null,
            is_string($input['runId'] ?? null) ? $input['runId'] : null,
            is_string($input['workspaceId'] ?? null) ? $input['workspaceId'] : null,
            is_string($input['patchId'] ?? null) ? $input['patchId'] : null,
            is_string($input['sessionId'] ?? null) ? $input['sessionId'] : null,
            $tags,
            $paths,
            $tokens,
            is_numeric($input['confidence'] ?? null) ? (float) $input['confidence'] : 0.8,
            0.5,
            KnowledgeRecord::defaultCompatibility(),
            '',
            '',
            is_array($input['provenance'] ?? null) ? $input['provenance'] : [
                'plane' => is_string($input['plane'] ?? null) ? $input['plane'] : 'knowledge',
                'capturedAtUtc' => $at,
            ],
            $capturedBy,
            self::CAPTURE_VERSION,
            $sourceArtifacts,
            $sourceEvents,
        );
        $record = $record->withSealedHashes();

        $settings = $this->settings->get();
        $providerId = is_string($settings['embeddingProviderId'] ?? null)
            ? $settings['embeddingProviderId']
            : 'local_lexical';
        $provider = $this->embeddings->get($providerId) ?? $this->embeddings->defaultProvider();
        try {
            $vectors = $provider->embed([$title . "\n" . $summary . "\n" . $body]);
            if (isset($vectors[0]) && is_array($vectors[0])) {
                $this->store->saveEmbedding($provider->id(), $record->knowledgeId(), $vectors[0]);
                $record = $record->withEmbeddingRef($provider->id() . ':' . $record->knowledgeId(), $at)->withSealedHashes();
            }
        } catch (\Throwable) {
            // embedding is optional
        }

        $this->store->save($record);
        $this->graph->linkRecord($record);
        $this->store->appendTimeline([
            'atUtc' => $at,
            'knowledgeId' => $record->knowledgeId(),
            'type' => 'captured',
            'kind' => $record->kind(),
            'message' => $record->title(),
        ]);

        return $record;
    }

    /**
     * @return list<KnowledgeRecord>
     */
    public function captureExecutionOutcome(
        string $missionId,
        string $runId,
        bool $succeeded,
        string $objective = '',
        ?string $projectId = null,
        ?string $sessionId = null,
        ?string $workspaceId = null,
        ?string $patchId = null,
        array $paths = [],
        array $extra = [],
    ): array {
        $records = [];
        $event = [
            'eventType' => $succeeded ? 'execution.succeeded' : 'execution.failed',
            'missionId' => $missionId,
            'runId' => $runId,
            'sessionId' => $sessionId,
            'atUtc' => Utc::now(),
        ];
        $base = [
            'missionId' => $missionId,
            'runId' => $runId,
            'projectId' => $projectId,
            'sessionId' => $sessionId,
            'workspaceId' => $workspaceId,
            'patchId' => $patchId,
            'paths' => $paths,
            'plane' => 'engineering_execution',
            'sourceEvents' => [$event],
            'sourceArtifacts' => is_array($extra['sourceArtifacts'] ?? null) ? $extra['sourceArtifacts'] : [],
            'provenance' => [
                'plane' => 'engineering_execution',
                'missionId' => $missionId,
                'runId' => $runId,
            ],
        ];

        $records[] = $this->capture(array_merge($base, [
            'kind' => KnowledgeRecord::KIND_MISSION,
            'title' => 'Mission execution: ' . ($objective !== '' ? $objective : $missionId),
            'summary' => ($succeeded ? 'Succeeded' : 'Failed') . ' execution for ' . $missionId,
            'body' => 'Objective: ' . $objective . "\nOutcome: " . ($succeeded ? 'succeeded' : 'failed'),
            'tags' => ['execution', $succeeded ? 'success' : 'failure'],
        ]), 'capture_adapter');

        if ($succeeded) {
            $records[] = $this->capture(array_merge($base, [
                'kind' => KnowledgeRecord::KIND_SUCCESS_PATTERN,
                'title' => 'Success pattern: ' . ($objective !== '' ? $objective : $missionId),
                'summary' => 'Successful engineering execution pattern',
                'body' => 'Reusable success for objective: ' . $objective,
                'tags' => ['success_pattern'],
            ]), 'capture_adapter');
            $records[] = $this->capture(array_merge($base, [
                'kind' => KnowledgeRecord::KIND_LESSON,
                'title' => 'Lesson: completed ' . ($objective !== '' ? $objective : $missionId),
                'summary' => 'Completed mission objective',
                'body' => 'Completed: ' . $objective,
                'tags' => ['lesson'],
            ]), 'capture_adapter');
        } else {
            $records[] = $this->capture(array_merge($base, [
                'kind' => KnowledgeRecord::KIND_FAILURE,
                'title' => 'Failure: ' . ($objective !== '' ? $objective : $missionId),
                'summary' => 'Execution failure memory',
                'body' => 'Failed/incomplete: ' . $objective . ' — review constraints and scope.',
                'tags' => ['failure'],
            ]), 'capture_adapter');
            $records[] = $this->capture(array_merge($base, [
                'kind' => KnowledgeRecord::KIND_LESSON,
                'title' => 'Lesson from failure: ' . $missionId,
                'summary' => 'Review constraints after failure',
                'body' => 'Failed/incomplete: ' . $objective . ' — review constraints and scope.',
                'tags' => ['lesson', 'failure'],
            ]), 'capture_adapter');
        }

        if ($workspaceId) {
            $records[] = $this->capture(array_merge($base, [
                'kind' => KnowledgeRecord::KIND_WORKSPACE,
                'title' => 'Workspace memory ' . $workspaceId,
                'summary' => 'Workspace used during execution',
                'body' => 'Workspace ' . $workspaceId . ' for mission ' . $missionId,
                'tags' => ['workspace'],
            ]), 'capture_adapter');
        }

        if ($patchId) {
            $records[] = $this->capture(array_merge($base, [
                'kind' => KnowledgeRecord::KIND_PATCH,
                'title' => 'Patch memory ' . $patchId,
                'summary' => 'Patch associated with execution',
                'body' => 'Patch ' . $patchId . ' for mission ' . $missionId,
                'tags' => ['patch'],
            ]), 'capture_adapter');
        }

        return $records;
    }

    public function capturePatchEvent(string $patchId, string $eventType, array $patchData = []): KnowledgeRecord
    {
        $kind = KnowledgeRecord::KIND_PATCH;
        if (str_contains($eventType, 'review')) {
            $kind = KnowledgeRecord::KIND_REVIEW;
        }
        if (str_contains($eventType, 'validation') || str_contains($eventType, 'check')) {
            $kind = KnowledgeRecord::KIND_VALIDATION;
        }

        return $this->capture([
            'kind' => $kind,
            'title' => 'Patch ' . $eventType . ': ' . $patchId,
            'summary' => 'Patch event ' . $eventType,
            'body' => json_encode([
                'patchId' => $patchId,
                'status' => $patchData['status'] ?? null,
                'score' => $patchData['score'] ?? null,
                'grade' => $patchData['grade'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'missionId' => is_string($patchData['missionId'] ?? null) ? $patchData['missionId'] : null,
            'runId' => is_string($patchData['runId'] ?? null) ? $patchData['runId'] : null,
            'projectId' => is_string($patchData['projectId'] ?? null) ? $patchData['projectId'] : null,
            'patchId' => $patchId,
            'workspaceId' => is_string($patchData['workspaceId'] ?? null) ? $patchData['workspaceId'] : null,
            'plane' => 'code_review',
            'tags' => ['patch', $eventType],
            'sourceEvents' => [['eventType' => $eventType, 'patchId' => $patchId, 'atUtc' => Utc::now()]],
            'sourceArtifacts' => is_array($patchData['sourceArtifacts'] ?? null) ? $patchData['sourceArtifacts'] : [],
            'provenance' => ['plane' => 'code_review', 'patchId' => $patchId, 'eventType' => $eventType],
            'paths' => is_array($patchData['paths'] ?? null) ? $patchData['paths'] : [],
        ], 'capture_adapter');
    }

    public function captureWorkspaceSeal(string $workspaceId, array $workspaceData = []): KnowledgeRecord
    {
        return $this->capture([
            'kind' => KnowledgeRecord::KIND_WORKSPACE,
            'title' => 'Sealed workspace ' . $workspaceId,
            'summary' => 'Workspace sealed',
            'body' => 'Workspace ' . $workspaceId . ' sealed with fingerprint '
                . (is_string($workspaceData['reproducibilityFingerprint'] ?? null) ? $workspaceData['reproducibilityFingerprint'] : ''),
            'workspaceId' => $workspaceId,
            'missionId' => is_string($workspaceData['missionId'] ?? null) ? $workspaceData['missionId'] : null,
            'runId' => is_string($workspaceData['runId'] ?? null) ? $workspaceData['runId'] : null,
            'projectId' => is_string($workspaceData['projectId'] ?? null) ? $workspaceData['projectId'] : null,
            'plane' => 'engineering_workspace',
            'tags' => ['workspace', 'seal'],
            'sourceEvents' => [['eventType' => 'workspace.sealed', 'workspaceId' => $workspaceId, 'atUtc' => Utc::now()]],
            'provenance' => ['plane' => 'engineering_workspace', 'workspaceId' => $workspaceId],
            'paths' => is_array($workspaceData['allowedPaths'] ?? null) ? $workspaceData['allowedPaths'] : [],
        ], 'capture_adapter');
    }

    public function archive(string $knowledgeId): ?KnowledgeRecord
    {
        $record = $this->store->find($knowledgeId);
        if ($record === null) {
            return null;
        }
        $at = Utc::now();
        $archived = $record->withStatus(KnowledgeRecord::STATUS_ARCHIVED, $at)->withSealedHashes();
        $this->store->save($archived);
        $this->store->appendTimeline([
            'atUtc' => $at,
            'knowledgeId' => $knowledgeId,
            'type' => 'archived',
            'message' => 'Archived by policy/operator',
        ]);

        return $archived;
    }

    public function forget(string $knowledgeId): ?KnowledgeRecord
    {
        $record = $this->store->find($knowledgeId);
        if ($record === null) {
            return null;
        }
        $at = Utc::now();
        $forgotten = $record->withForgottenBody($at);
        $this->store->save($forgotten);
        $this->store->appendTimeline([
            'atUtc' => $at,
            'knowledgeId' => $knowledgeId,
            'type' => 'forgotten',
            'message' => 'Forgotten by policy/operator',
        ]);

        return $forgotten;
    }

    public function applyRetention(): int
    {
        $changed = 0;
        foreach ($this->store->list(null, null, KnowledgeRecord::STATUS_ACTIVE) as $record) {
            if ($this->archivePolicy->shouldArchive($record)) {
                $this->archive($record->knowledgeId());
                $changed++;
            }
        }
        foreach ($this->store->list(null, null, KnowledgeRecord::STATUS_ARCHIVED) as $record) {
            if ($this->forgetPolicy->shouldForget($record)) {
                $this->forget($record->knowledgeId());
                $changed++;
            }
        }

        return $changed;
    }

    private function looksSensitive(string $text): bool
    {
        return (bool) preg_match('/(?i)(api[_-]?key|password|secret)\s*[:=]\s*\S+/', $text);
    }
}
