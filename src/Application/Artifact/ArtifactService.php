<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Application façade for Artifact & Workspace Management.
 */
final class ArtifactService
{
    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly ArtifactStore $store,
    ) {
    }

    public function ensureWorkspace(
        string $missionId,
        string $runId,
        string $occurredAtUtc,
        ?string $projectId = null,
    ): Workspace {
        return $this->workspaces->ensure($missionId, $runId, $occurredAtUtc, $projectId);
    }

    public function getWorkspace(string $workspaceId): Workspace
    {
        return $this->workspaces->get($workspaceId);
    }

    public function put(
        string $missionId,
        string $runId,
        string $artifactId,
        string $kind,
        string $name,
        string $contents,
        string $occurredAtUtc,
        bool $persistent = true,
        string $contentType = 'application/octet-stream',
        ?ArtifactMetadata $metadata = null,
        ?string $projectId = null,
    ): Artifact {
        $workspace = $this->workspaces->ensure($missionId, $runId, $occurredAtUtc, $projectId);
        $this->assertMutable($workspace);

        $meta = $metadata ?? new ArtifactMetadata($missionId, $runId, $projectId);
        if ($meta->missionId() === '' || $meta->runId() === '') {
            $meta = new ArtifactMetadata(
                $missionId,
                $runId,
                $projectId ?? $meta->projectId(),
                $meta->workflowId(),
                $meta->workflowVersion(),
                $meta->workflowHash(),
                $meta->stepId(),
                $meta->producedBy(),
                $meta->labels(),
                $meta->relatedRefs(),
                $meta->custom(),
            );
        }

        return $this->store->put(
            $workspace,
            $artifactId,
            new ArtifactKind($kind),
            $name,
            $contents,
            $contentType,
            $persistent,
            $meta,
            $occurredAtUtc,
        );
    }

    public function get(string $workspaceId, string $artifactId): Artifact
    {
        return $this->store->get($workspaceId, $artifactId);
    }

    public function readContents(string $workspaceId, string $artifactId): string
    {
        return $this->store->readContents($workspaceId, $artifactId);
    }

    /**
     * @return list<Artifact>
     */
    public function list(string $workspaceId, ?string $kind = null): array
    {
        return $this->store->list($workspaceId, $kind);
    }

    public function manifest(string $workspaceId): ArtifactManifest
    {
        return $this->store->manifest($workspaceId);
    }

    public function seal(string $workspaceId, string $occurredAtUtc): Workspace
    {
        $workspace = $this->workspaces->get($workspaceId);
        if ($workspace->isSealed()) {
            return $workspace;
        }

        // Drop non-persistent artifacts before seal.
        $manifest = $this->store->manifest($workspaceId);
        foreach ($manifest->artifacts() as $artifact) {
            if (!$artifact->persistent()) {
                $this->store->remove($workspaceId, $artifact->artifactId(), $occurredAtUtc);
            }
        }

        $sealed = $this->workspaces->seal($workspaceId, $occurredAtUtc);
        $manifest = $this->store->manifest($workspaceId)->withStatus(Workspace::STATUS_SEALED, $occurredAtUtc);
        $this->store->saveManifest($manifest);

        return $sealed;
    }

    /**
     * Create a PharData archive of persistent artifacts (read-only after seal).
     */
    public function archive(string $workspaceId, string $occurredAtUtc): Artifact
    {
        $workspace = $this->workspaces->get($workspaceId);
        if (!$workspace->isSealed() && $workspace->status() !== Workspace::STATUS_ARCHIVED) {
            $workspace = $this->seal($workspaceId, $occurredAtUtc);
        }

        if (!$this->store instanceof ArchiveCapableArtifactStore) {
            throw new \RuntimeException('ArtifactStore does not support archive.');
        }

        $artifact = $this->store->archive($workspace, $occurredAtUtc);
        $this->workspaces->markArchived($workspaceId, $occurredAtUtc);
        $manifest = $this->store->manifest($workspaceId)->withStatus(Workspace::STATUS_ARCHIVED, $occurredAtUtc);
        $this->store->saveManifest($manifest);

        return $artifact;
    }

    public function setWorkflowMetadata(
        string $workspaceId,
        ?string $workflowId,
        ?string $workflowVersion,
        ?string $workflowHash,
    ): ArtifactManifest {
        $manifest = $this->store->manifest($workspaceId)
            ->withWorkflow($workflowId, $workflowVersion, $workflowHash);
        $this->store->saveManifest($manifest);

        return $manifest;
    }

    private function assertMutable(Workspace $workspace): void
    {
        if (!$workspace->isActive()) {
            throw new \RuntimeException(
                'Workspace is sealed; put/overwrite/delete are forbidden (' . $workspace->status() . ').'
            );
        }
    }
}
