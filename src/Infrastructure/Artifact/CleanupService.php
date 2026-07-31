<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Artifact;

use Aep\Application\Artifact\ArtifactStore;
use Aep\Application\Artifact\Workspace;
use Aep\Application\Artifact\WorkspaceManager;

/**
 * Cleanup and retention enforcement for artifact workspaces.
 */
final class CleanupService
{
    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly ArtifactStore $store,
        private readonly RetentionPolicy $retention = new RetentionPolicy(),
    ) {
    }

    /**
     * Remove non-persistent artifacts from an active workspace (manifest-driven).
     *
     * @return list<string> removed artifact ids
     */
    public function cleanupNonPersistent(string $workspaceId, string $occurredAtUtc): array
    {
        $workspace = $this->workspaces->get($workspaceId);
        if (!$workspace->isActive()) {
            throw new \RuntimeException('cleanupNonPersistent requires an active workspace.');
        }

        $removed = [];
        foreach ($this->store->manifest($workspaceId)->artifacts() as $artifact) {
            if (!$artifact->persistent()) {
                $this->store->remove($workspaceId, $artifact->artifactId(), $occurredAtUtc);
                $removed[] = $artifact->artifactId();
            }
        }

        return $removed;
    }

    /**
     * Apply retention policy and purge eligible workspace trees.
     *
     * @return list<string> purged workspace ids
     */
    public function applyRetention(string $nowUtc): array
    {
        $workspaces = $this->workspaces->listWorkspaces();

        // Keep latest N per mission among sealed/archived.
        $byMission = [];
        foreach ($workspaces as $workspace) {
            if ($workspace->status() === Workspace::STATUS_PURGED || $workspace->status() === Workspace::STATUS_ACTIVE) {
                continue;
            }
            $byMission[$workspace->missionId()][] = $workspace;
        }

        $protected = [];
        foreach ($byMission as $missionId => $list) {
            usort(
                $list,
                static fn (Workspace $a, Workspace $b): int => strcmp($b->updatedAtUtc(), $a->updatedAtUtc())
            );
            $keep = array_slice($list, 0, $this->retention->keepLatestNPerMission());
            foreach ($keep as $workspace) {
                $protected[$workspace->workspaceId()] = true;
            }
            unset($missionId);
        }

        $purged = [];
        foreach ($workspaces as $workspace) {
            if (isset($protected[$workspace->workspaceId()])) {
                continue;
            }
            if (!$this->retention->shouldPurge($workspace, $nowUtc)) {
                continue;
            }
            $this->workspaces->markPurged($workspace->workspaceId(), $nowUtc);
            $this->workspaces->deleteWorkspaceTree($workspace->workspaceId());
            $purged[] = $workspace->workspaceId();
        }

        return $purged;
    }

    public function retention(): RetentionPolicy
    {
        return $this->retention;
    }
}
