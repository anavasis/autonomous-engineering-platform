<?php

declare(strict_types=1);

namespace Aep\Application\Knowledge\Model;

/**
 * Durable unit of engineering knowledge (ORCH-AEP-006).
 */
final class KnowledgeRecord
{
    public const SCHEMA_VERSION = 1;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_FORGOTTEN = 'forgotten';
    public const STATUS_QUARANTINED = 'quarantined';

    public const KIND_MISSION = 'mission';
    public const KIND_WORKSPACE = 'workspace';
    public const KIND_PATCH = 'patch';
    public const KIND_REVIEW = 'review';
    public const KIND_VALIDATION = 'validation';
    public const KIND_FAILURE = 'failure';
    public const KIND_SUCCESS_PATTERN = 'success_pattern';
    public const KIND_LESSON = 'lesson';
    public const KIND_CONSTRAINT = 'constraint';
    public const KIND_ENGINEERING = 'engineering';

    /**
     * @param list<string> $tags
     * @param list<string> $paths
     * @param list<string> $tokens
     * @param array<string, mixed> $compatibility
     * @param array<string, mixed> $provenance
     * @param list<string> $sourceArtifacts
     * @param list<array<string, mixed>> $sourceEvents
     * @param list<array<string, mixed>> $links
     */
    public function __construct(
        private string $knowledgeId,
        private string $stableKey,
        private string $kind,
        private string $status,
        private string $scope,
        private string $title,
        private string $summary,
        private string $body,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private ?string $projectId = null,
        private ?string $missionId = null,
        private ?string $runId = null,
        private ?string $workspaceId = null,
        private ?string $patchId = null,
        private ?string $sessionId = null,
        private array $tags = [],
        private array $paths = [],
        private array $tokens = [],
        private float $confidence = 0.8,
        private float $usefulness = 0.5,
        private array $compatibility = [],
        private string $reproducibilityFingerprint = '',
        private string $integrityHash = '',
        private array $provenance = [],
        private string $capturedBy = 'system',
        private string $captureVersion = '0.6.0',
        private array $sourceArtifacts = [],
        private array $sourceEvents = [],
        private ?string $embeddingRef = null,
        private ?string $archivedAtUtc = null,
        private array $links = [],
        private int $schemaVersion = self::SCHEMA_VERSION,
        private int $hitCount = 0,
    ) {
    }

    public function knowledgeId(): string
    {
        return $this->knowledgeId;
    }

    public function stableKey(): string
    {
        return $this->stableKey;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function missionId(): ?string
    {
        return $this->missionId;
    }

    public function patchId(): ?string
    {
        return $this->patchId;
    }

    public function workspaceId(): ?string
    {
        return $this->workspaceId;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    public function usefulness(): float
    {
        return $this->usefulness;
    }

    public function reproducibilityFingerprint(): string
    {
        return $this->reproducibilityFingerprint;
    }

    public function integrityHash(): string
    {
        return $this->integrityHash;
    }

    public function embeddingRef(): ?string
    {
        return $this->embeddingRef;
    }

    public function hitCount(): int
    {
        return $this->hitCount;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    public function withStatus(string $status, string $atUtc): self
    {
        $clone = clone $this;
        $clone->status = $status;
        $clone->updatedAtUtc = $atUtc;
        if ($status === self::STATUS_ARCHIVED || $status === self::STATUS_FORGOTTEN) {
            $clone->archivedAtUtc = $atUtc;
        }

        return $clone;
    }

    public function withUsefulness(float $usefulness, int $hitCount, string $atUtc): self
    {
        $clone = clone $this;
        $clone->usefulness = max(0.0, min(1.0, $usefulness));
        $clone->hitCount = max(0, $hitCount);
        $clone->updatedAtUtc = $atUtc;

        return $clone;
    }

    public function withEmbeddingRef(?string $ref, string $atUtc): self
    {
        $clone = clone $this;
        $clone->embeddingRef = $ref;
        $clone->updatedAtUtc = $atUtc;

        return $clone;
    }

    public function withForgottenBody(string $atUtc): self
    {
        $clone = clone $this;
        $clone->status = self::STATUS_FORGOTTEN;
        $clone->body = '[forgotten]';
        $clone->summary = '[forgotten]';
        $clone->archivedAtUtc = $atUtc;
        $clone->updatedAtUtc = $atUtc;
        $clone->integrityHash = self::computeIntegrity($clone->payloadForIntegrity());

        return $clone;
    }

    public function withSealedHashes(): self
    {
        $clone = clone $this;
        $clone->reproducibilityFingerprint = self::computeFingerprint([
            'stableKey' => $this->stableKey,
            'kind' => $this->kind,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'sourceEvents' => $this->sourceEvents,
            'sourceArtifacts' => $this->sourceArtifacts,
        ]);
        $clone->integrityHash = self::computeIntegrity($clone->payloadForIntegrity());

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'knowledgeId' => $this->knowledgeId,
            'stableKey' => $this->stableKey,
            'kind' => $this->kind,
            'status' => $this->status,
            'scope' => $this->scope,
            'projectId' => $this->projectId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'workspaceId' => $this->workspaceId,
            'patchId' => $this->patchId,
            'sessionId' => $this->sessionId,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'tags' => $this->tags,
            'paths' => $this->paths,
            'tokens' => $this->tokens,
            'confidence' => $this->confidence,
            'usefulness' => $this->usefulness,
            'hitCount' => $this->hitCount,
            'compatibility' => $this->compatibility !== [] ? $this->compatibility : self::defaultCompatibility(),
            'reproducibilityFingerprint' => $this->reproducibilityFingerprint,
            'integrityHash' => $this->integrityHash,
            'provenance' => $this->provenance,
            'capturedBy' => $this->capturedBy,
            'captureVersion' => $this->captureVersion,
            'sourceArtifacts' => $this->sourceArtifacts,
            'sourceEvents' => $this->sourceEvents,
            'embeddingRef' => $this->embeddingRef,
            'links' => $this->links,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'archivedAtUtc' => $this->archivedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['knowledgeId'] ?? null) ? $data['knowledgeId'] : '',
            is_string($data['stableKey'] ?? null) ? $data['stableKey'] : '',
            is_string($data['kind'] ?? null) ? $data['kind'] : self::KIND_ENGINEERING,
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_ACTIVE,
            is_string($data['scope'] ?? null) ? $data['scope'] : 'project',
            is_string($data['title'] ?? null) ? $data['title'] : '',
            is_string($data['summary'] ?? null) ? $data['summary'] : '',
            is_string($data['body'] ?? null) ? $data['body'] : '',
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            is_string($data['projectId'] ?? null) ? $data['projectId'] : null,
            is_string($data['missionId'] ?? null) ? $data['missionId'] : null,
            is_string($data['runId'] ?? null) ? $data['runId'] : null,
            is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : null,
            is_string($data['patchId'] ?? null) ? $data['patchId'] : null,
            is_string($data['sessionId'] ?? null) ? $data['sessionId'] : null,
            self::stringList($data['tags'] ?? []),
            self::stringList($data['paths'] ?? []),
            self::stringList($data['tokens'] ?? []),
            is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.8,
            is_numeric($data['usefulness'] ?? null) ? (float) $data['usefulness'] : 0.5,
            is_array($data['compatibility'] ?? null) ? $data['compatibility'] : self::defaultCompatibility(),
            is_string($data['reproducibilityFingerprint'] ?? null) ? $data['reproducibilityFingerprint'] : '',
            is_string($data['integrityHash'] ?? null) ? $data['integrityHash'] : '',
            is_array($data['provenance'] ?? null) ? $data['provenance'] : [],
            is_string($data['capturedBy'] ?? null) ? $data['capturedBy'] : 'system',
            is_string($data['captureVersion'] ?? null) ? $data['captureVersion'] : '0.6.0',
            self::stringList($data['sourceArtifacts'] ?? []),
            self::eventList($data['sourceEvents'] ?? []),
            is_string($data['embeddingRef'] ?? null) ? $data['embeddingRef'] : null,
            is_string($data['archivedAtUtc'] ?? null) ? $data['archivedAtUtc'] : null,
            is_array($data['links'] ?? null) ? array_values($data['links']) : [],
            is_int($data['schemaVersion'] ?? null) ? $data['schemaVersion'] : self::SCHEMA_VERSION,
            is_int($data['hitCount'] ?? null) ? $data['hitCount'] : 0,
        );
    }

    /** @return array<string, mixed> */
    public static function defaultCompatibility(): array
    {
        return [
            'MissionDomain' => '1',
            'EngineeringExecutionProvider' => '0.3',
            'EngineeringWorkspace' => '0.4',
            'CodeReviewPatchPipeline' => '0.5',
            'aepMinVersion' => '0.6.0',
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function computeFingerprint(array $payload): string
    {
        return 'sha256:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    public static function computeIntegrity(array $payload): string
    {
        return 'sha256:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function makeStableKey(string $kind, string $topic, string $sourceFingerprint): string
    {
        return hash('sha256', strtolower($kind) . '|' . trim($topic) . '|' . $sourceFingerprint);
    }

    public static function makeId(): string
    {
        return 'knw_' . bin2hex(random_bytes(8));
    }

    /** @return array<string, mixed> */
    private function payloadForIntegrity(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'knowledgeId' => $this->knowledgeId,
            'stableKey' => $this->stableKey,
            'kind' => $this->kind,
            'status' => $this->status,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'compatibility' => $this->compatibility,
            'reproducibilityFingerprint' => $this->reproducibilityFingerprint,
            'provenance' => $this->provenance,
            'capturedBy' => $this->capturedBy,
            'captureVersion' => $this->captureVersion,
            'sourceArtifacts' => $this->sourceArtifacts,
            'sourceEvents' => $this->sourceEvents,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    private static function eventList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
