<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Manifest is the single source of truth for workspace artifact membership.
 */
final class ArtifactManifest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<Artifact> $artifacts
     */
    public function __construct(
        private string $workspaceId,
        private string $missionId,
        private string $runId,
        private string $status,
        private string $createdAtUtc,
        private string $updatedAtUtc,
        private array $artifacts = [],
        private ?string $projectId = null,
        private ?string $workflowId = null,
        private ?string $workflowVersion = null,
        private ?string $workflowHash = null,
    ) {
        foreach ($this->artifacts as $artifact) {
            if (!$artifact instanceof Artifact) {
                throw new \InvalidArgumentException('Manifest artifacts must be Artifact instances.');
            }
        }
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    public function missionId(): string
    {
        return $this->missionId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAtUtc(): string
    {
        return $this->createdAtUtc;
    }

    public function updatedAtUtc(): string
    {
        return $this->updatedAtUtc;
    }

    public function workflowId(): ?string
    {
        return $this->workflowId;
    }

    public function workflowVersion(): ?string
    {
        return $this->workflowVersion;
    }

    public function workflowHash(): ?string
    {
        return $this->workflowHash;
    }

    /**
     * @return list<Artifact>
     */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    public function find(string $artifactId): ?Artifact
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->artifactId() === $artifactId) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * @return list<Artifact>
     */
    public function byKind(string $kind): array
    {
        $out = [];
        foreach ($this->artifacts as $artifact) {
            if ($artifact->kind()->is($kind)) {
                $out[] = $artifact;
            }
        }

        return $out;
    }

    public function withArtifact(Artifact $artifact, string $updatedAtUtc): self
    {
        $items = [];
        $replaced = false;
        foreach ($this->artifacts as $existing) {
            if ($existing->artifactId() === $artifact->artifactId()) {
                $items[] = $artifact;
                $replaced = true;
            } else {
                $items[] = $existing;
            }
        }
        if (!$replaced) {
            $items[] = $artifact;
        }

        return new self(
            $this->workspaceId,
            $this->missionId,
            $this->runId,
            $this->status,
            $this->createdAtUtc,
            $updatedAtUtc,
            $items,
            $this->projectId,
            $this->workflowId,
            $this->workflowVersion,
            $this->workflowHash,
        );
    }

    public function withoutArtifact(string $artifactId, string $updatedAtUtc): self
    {
        $items = [];
        foreach ($this->artifacts as $existing) {
            if ($existing->artifactId() !== $artifactId) {
                $items[] = $existing;
            }
        }

        return new self(
            $this->workspaceId,
            $this->missionId,
            $this->runId,
            $this->status,
            $this->createdAtUtc,
            $updatedAtUtc,
            $items,
            $this->projectId,
            $this->workflowId,
            $this->workflowVersion,
            $this->workflowHash,
        );
    }

    public function withStatus(string $status, string $updatedAtUtc): self
    {
        return new self(
            $this->workspaceId,
            $this->missionId,
            $this->runId,
            $status,
            $this->createdAtUtc,
            $updatedAtUtc,
            $this->artifacts,
            $this->projectId,
            $this->workflowId,
            $this->workflowVersion,
            $this->workflowHash,
        );
    }

    public function withWorkflow(?string $workflowId, ?string $workflowVersion, ?string $workflowHash): self
    {
        return new self(
            $this->workspaceId,
            $this->missionId,
            $this->runId,
            $this->status,
            $this->createdAtUtc,
            $this->updatedAtUtc,
            $this->artifacts,
            $this->projectId,
            $workflowId,
            $workflowVersion,
            $workflowHash,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'workspaceId' => $this->workspaceId,
            'missionId' => $this->missionId,
            'runId' => $this->runId,
            'projectId' => $this->projectId,
            'status' => $this->status,
            'createdAtUtc' => $this->createdAtUtc,
            'updatedAtUtc' => $this->updatedAtUtc,
            'workflowId' => $this->workflowId,
            'workflowVersion' => $this->workflowVersion,
            'workflowHash' => $this->workflowHash,
            'artifacts' => array_map(static fn (Artifact $a) => $a->toArray(), $this->artifacts),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $artifacts = [];
        $rows = $data['artifacts'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $artifacts[] = Artifact::fromArray($row);
                }
            }
        }

        return new self(
            is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : '',
            is_string($data['missionId'] ?? null) ? $data['missionId'] : '',
            is_string($data['runId'] ?? null) ? $data['runId'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : Workspace::STATUS_ACTIVE,
            is_string($data['createdAtUtc'] ?? null) ? $data['createdAtUtc'] : '',
            is_string($data['updatedAtUtc'] ?? null) ? $data['updatedAtUtc'] : '',
            $artifacts,
            isset($data['projectId']) && is_string($data['projectId']) ? $data['projectId'] : null,
            isset($data['workflowId']) && is_string($data['workflowId']) ? $data['workflowId'] : null,
            isset($data['workflowVersion']) && is_string($data['workflowVersion']) ? $data['workflowVersion'] : null,
            isset($data['workflowHash']) && is_string($data['workflowHash']) ? $data['workflowHash'] : null,
        );
    }
}
