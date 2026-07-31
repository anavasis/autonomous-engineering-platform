<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Service;

use Aep\Application\EngineeringWorkspace\Port\WorkspaceSettingsStore;

final class WorkspaceQueryService
{
    public function __construct(
        private readonly EngineeringWorkspaceService $workspaces,
        private readonly WorkspaceSettingsStore $settings,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $missionId = null, ?string $status = null): array
    {
        $items = [];
        foreach ($this->workspaces->list($missionId, $status) as $ws) {
            $items[] = $this->summarize($ws->toArray());
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function get(string $workspaceId): ?array
    {
        $ws = $this->workspaces->get($workspaceId);
        if ($ws === null) {
            return null;
        }
        $data = $ws->toArray();
        $data['timeline'] = $this->workspaces->timeline($workspaceId);
        $health = $this->workspaces->healthCheck($ws);
        $data['health'] = $health->toArray();

        return $data;
    }

    /** @return array<string, mixed>|null */
    public function forMission(string $missionId): ?array
    {
        $ws = $this->workspaces->forMission($missionId);

        return $ws?->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function timeline(string $workspaceId): array
    {
        return $this->workspaces->timeline($workspaceId);
    }

    /** @return array<string, mixed>|null */
    public function mounts(string $workspaceId): ?array
    {
        $ws = $this->workspaces->get($workspaceId);
        if ($ws === null) {
            return null;
        }

        return [
            'workspaceId' => $workspaceId,
            'mounts' => $ws->mounts()->toArray(),
            'promptPreviewPath' => 'mounts/prompt/PROMPT.md',
            'contextRoot' => 'mounts/context',
            'artifactsRoot' => 'mounts/artifacts',
        ];
    }

    /** @return array<string, mixed>|null */
    public function size(string $workspaceId): ?array
    {
        $ws = $this->workspaces->get($workspaceId);
        if ($ws === null) {
            return null;
        }

        return [
            'workspaceId' => $workspaceId,
            'quota' => $ws->quota()->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings->get();
    }

    /** @param array<string, mixed> $patch */
    public function updateSettings(array $patch): array
    {
        return $this->settings->update($patch);
    }

    /** @param array<string, mixed> $data */
    private function summarize(array $data): array
    {
        return [
            'workspaceId' => $data['workspaceId'] ?? null,
            'missionId' => $data['missionId'] ?? null,
            'runId' => $data['runId'] ?? null,
            'projectId' => $data['projectId'] ?? null,
            'status' => $data['status'] ?? null,
            'message' => $data['message'] ?? null,
            'updatedAtUtc' => $data['updatedAtUtc'] ?? null,
            'reproducibilityFingerprint' => $data['reproducibilityFingerprint'] ?? null,
            'quota' => $data['quota'] ?? null,
            'health' => $data['health'] ?? null,
            'schemaVersion' => $data['schemaVersion'] ?? null,
        ];
    }
}
