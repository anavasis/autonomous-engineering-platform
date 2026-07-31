<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Application port for artifact bytes + manifest updates.
 *
 * Manifest is the single source of truth; implementations must not scan
 * the filesystem for discovery during normal get/list operations.
 */
interface ArtifactStore
{
    public function put(
        Workspace $workspace,
        string $artifactId,
        ArtifactKind $kind,
        string $name,
        string $contents,
        string $contentType,
        bool $persistent,
        ArtifactMetadata $metadata,
        string $occurredAtUtc,
    ): Artifact;

    public function get(string $workspaceId, string $artifactId): Artifact;

    public function readContents(string $workspaceId, string $artifactId): string;

    /**
     * @return list<Artifact>
     */
    public function list(string $workspaceId, ?string $kind = null): array;

    public function manifest(string $workspaceId): ArtifactManifest;

    public function saveManifest(ArtifactManifest $manifest): void;

    public function remove(string $workspaceId, string $artifactId, string $occurredAtUtc): void;

    public function absolutePath(string $workspaceId, string $relativePath): string;
}
