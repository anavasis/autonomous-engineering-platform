<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Model;

final class Program
{
    public const SCHEMA_VERSION = 1;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PLANNING = 'planning';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_SCHEDULING = 'scheduling';
    public const STATUS_RUNNING = 'running';
    public const STATUS_REPLANNING = 'replanning';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @param array<string, mixed> $constraints
     * @param array<string, mixed> $estimates
     * @param array<string, mixed> $allocations
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $criticalPath
     * @param array<string, mixed> $compatibility
     * @param list<string> $knowledgeRefs
     */
    public function __construct(
        private string $programId,
        private string $title,
        private string $objective,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private MissionGraph $graph = new MissionGraph(),
        private ?string $projectId = null,
        private string $priority = 'normal',
        private array $constraints = [],
        private array $estimates = [],
        private array $allocations = [],
        private array $schedule = [],
        private array $criticalPath = [],
        private array $compatibility = [],
        private string $reproducibilityFingerprint = '',
        private string $integrityHash = '',
        private array $knowledgeRefs = [],
        private int $schemaVersion = self::SCHEMA_VERSION,
        private int $snapshotSequence = 0,
        private bool $paused = false,
    ) {
    }

    public function programId(): string { return $this->programId; }
    public function title(): string { return $this->title; }
    public function objective(): string { return $this->objective; }
    public function status(): string { return $this->status; }
    public function projectId(): ?string { return $this->projectId; }
    public function priority(): string { return $this->priority; }
    public function graph(): MissionGraph { return $this->graph; }
    /** @return array<string, mixed> */
    public function constraints(): array { return $this->constraints; }
    /** @return array<string, mixed> */
    public function estimates(): array { return $this->estimates; }
    /** @return array<string, mixed> */
    public function allocations(): array { return $this->allocations; }
    /** @return array<string, mixed> */
    public function schedule(): array { return $this->schedule; }
    /** @return array<string, mixed> */
    public function criticalPath(): array { return $this->criticalPath; }
    public function reproducibilityFingerprint(): string { return $this->reproducibilityFingerprint; }
    public function integrityHash(): string { return $this->integrityHash; }
    public function snapshotSequence(): int { return $this->snapshotSequence; }
    public function isPaused(): bool { return $this->paused || $this->status === self::STATUS_PAUSED; }
    public function updatedAtUtc(): string { return $this->updatedAtUtc; }
    public function createdAtUtc(): string { return $this->createdAtUtc; }

    public function withStatus(string $status, string $atUtc): self
    {
        $c = clone $this;
        $c->status = $status;
        $c->updatedAtUtc = $atUtc;
        $c->paused = $status === self::STATUS_PAUSED;
        return $c;
    }

    public function withGraph(MissionGraph $graph, string $atUtc): self
    {
        $c = clone $this;
        $c->graph = $graph;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withSchedule(array $schedule, string $atUtc): self
    {
        $c = clone $this;
        $c->schedule = $schedule;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withAllocations(array $allocations, string $atUtc): self
    {
        $c = clone $this;
        $c->allocations = $allocations;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withCriticalPath(array $criticalPath, string $atUtc): self
    {
        $c = clone $this;
        $c->criticalPath = $criticalPath;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withEstimates(array $estimates, string $atUtc): self
    {
        $c = clone $this;
        $c->estimates = $estimates;
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withKnowledgeRefs(array $refs, string $atUtc): self
    {
        $c = clone $this;
        $c->knowledgeRefs = array_values($refs);
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function withPaused(bool $paused, string $atUtc): self
    {
        $c = clone $this;
        $c->paused = $paused;
        $c->status = $paused ? self::STATUS_PAUSED : ($this->status === self::STATUS_PAUSED ? self::STATUS_RUNNING : $this->status);
        $c->updatedAtUtc = $atUtc;
        return $c;
    }

    public function bumpSnapshotSequence(): self
    {
        $c = clone $this;
        $c->snapshotSequence = $this->snapshotSequence + 1;
        return $c;
    }

    public function withSealedHashes(): self
    {
        $c = clone $this;
        $c->compatibility = $this->compatibility !== [] ? $this->compatibility : self::defaultCompatibility();
        $c->reproducibilityFingerprint = 'sha256:' . hash('sha256', json_encode([
            'objective' => $this->objective,
            'constraints' => $this->constraints,
            'graph' => $this->graph->toArray(),
        ], JSON_THROW_ON_ERROR));
        $payload = $c->toArray();
        unset($payload['integrityHash']);
        $c->integrityHash = 'sha256:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $c;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'programId' => $this->programId,
            'title' => $this->title,
            'objective' => $this->objective,
            'status' => $this->status,
            'projectId' => $this->projectId,
            'priority' => $this->priority,
            'constraints' => $this->constraints,
            'graph' => $this->graph->toArray(),
            'schedule' => $this->schedule,
            'allocations' => $this->allocations,
            'estimates' => $this->estimates,
            'criticalPath' => $this->criticalPath,
            'compatibility' => $this->compatibility !== [] ? $this->compatibility : self::defaultCompatibility(),
            'reproducibilityFingerprint' => $this->reproducibilityFingerprint,
            'integrityHash' => $this->integrityHash,
            'knowledgeRefs' => $this->knowledgeRefs,
            'snapshotSequence' => $this->snapshotSequence,
            'paused' => $this->paused,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $graph = isset($data['graph']) && is_array($data['graph'])
            ? MissionGraph::fromArray($data['graph'])
            : new MissionGraph();
        $refs = [];
        if (isset($data['knowledgeRefs']) && is_array($data['knowledgeRefs'])) {
            foreach ($data['knowledgeRefs'] as $r) {
                if (is_string($r)) {
                    $refs[] = $r;
                }
            }
        }

        return new self(
            is_string($data['programId'] ?? null) ? $data['programId'] : '',
            is_string($data['title'] ?? null) ? $data['title'] : '',
            is_string($data['objective'] ?? null) ? $data['objective'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_DRAFT,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            $graph,
            is_string($data['projectId'] ?? null) ? $data['projectId'] : null,
            is_string($data['priority'] ?? null) ? $data['priority'] : 'normal',
            is_array($data['constraints'] ?? null) ? $data['constraints'] : [],
            is_array($data['estimates'] ?? null) ? $data['estimates'] : [],
            is_array($data['allocations'] ?? null) ? $data['allocations'] : [],
            is_array($data['schedule'] ?? null) ? $data['schedule'] : [],
            is_array($data['criticalPath'] ?? null) ? $data['criticalPath'] : [],
            is_array($data['compatibility'] ?? null) ? $data['compatibility'] : self::defaultCompatibility(),
            is_string($data['reproducibilityFingerprint'] ?? null) ? $data['reproducibilityFingerprint'] : '',
            is_string($data['integrityHash'] ?? null) ? $data['integrityHash'] : '',
            $refs,
            is_int($data['schemaVersion'] ?? null) ? $data['schemaVersion'] : self::SCHEMA_VERSION,
            is_int($data['snapshotSequence'] ?? null) ? $data['snapshotSequence'] : 0,
            ($data['paused'] ?? false) === true,
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
            'EngineeringKnowledgeMemory' => '0.6',
            'aepMinVersion' => '0.7.0',
        ];
    }

    public static function makeId(): string
    {
        return 'prg_' . bin2hex(random_bytes(6));
    }
}
