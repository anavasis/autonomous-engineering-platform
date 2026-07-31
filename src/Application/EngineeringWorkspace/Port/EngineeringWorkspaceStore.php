<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Port;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;

/**
 * Pluggable workspace storage. Application code depends only on this port.
 * Filesystem is the first implementation; future backends need no Application changes.
 */
interface EngineeringWorkspaceStore
{
    public function save(EngineeringWorkspace $workspace): void;

    public function find(string $workspaceId): ?EngineeringWorkspace;

    public function findByMissionRun(string $missionId, string $runId): ?EngineeringWorkspace;

    public function findBySession(string $sessionId): ?EngineeringWorkspace;

    /**
     * @return list<EngineeringWorkspace>
     */
    public function list(?string $missionId = null, ?string $status = null): array;

    /** Ensure durable tree exists; return absolute root path. */
    public function ensureTree(string $workspaceId): string;

    public function writeFile(string $workspaceId, string $relativePath, string $contents): void;

    public function readFile(string $workspaceId, string $relativePath): ?string;

    public function copyFile(string $workspaceId, string $absoluteSource, string $relativeDest): void;

    /**
     * @return array{bytes: int, files: int}
     */
    public function measureSize(string $workspaceId): array;

    /** @param array<string, mixed> $event */
    public function appendTimeline(string $workspaceId, array $event): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(string $workspaceId): array;

    /**
     * @param array<string, mixed> $meta
     */
    public function saveSnapshot(string $workspaceId, string $snapshotId, array $meta): void;

    public function restoreSnapshot(string $workspaceId, string $snapshotId): void;

    public function acquireLock(string $workspaceId, string $owner, int $ttlSeconds = 600): bool;

    public function releaseLock(string $workspaceId, string $owner): void;

    public function deleteTree(string $workspaceId): void;

    public function markIndex(EngineeringWorkspace $workspace): void;
}
