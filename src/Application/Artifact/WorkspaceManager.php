<?php

declare(strict_types=1);

namespace Aep\Application\Artifact;

/**
 * Application port for mission-run workspace lifecycle.
 */
interface WorkspaceManager
{
    public function ensure(
        string $missionId,
        string $runId,
        string $occurredAtUtc,
        ?string $projectId = null,
    ): Workspace;

    public function get(string $workspaceId): Workspace;

    public function seal(string $workspaceId, string $occurredAtUtc): Workspace;

    public function markArchived(string $workspaceId, string $occurredAtUtc): Workspace;

    public function markPurged(string $workspaceId, string $occurredAtUtc): Workspace;

    public function exists(string $workspaceId): bool;

    /**
     * Descriptor listing for retention (from workspace.json only — no artifact scans).
     *
     * @return list<Workspace>
     */
    public function listWorkspaces(): array;

    public function deleteWorkspaceTree(string $workspaceId): void;
}
