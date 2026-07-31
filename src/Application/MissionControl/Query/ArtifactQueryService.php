<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\Artifact\ArtifactService;
use Aep\Application\Artifact\WorkspaceManager;

final class ArtifactQueryService
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly WorkspaceManager $workspaces,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWorkspaces(?string $missionId = null): array
    {
        $items = [];
        foreach ($this->workspaces->listWorkspaces() as $workspace) {
            if ($missionId !== null && $workspace->missionId() !== $missionId) {
                continue;
            }
            $items[] = [
                'workspaceId' => $workspace->workspaceId(),
                'missionId' => $workspace->missionId(),
                'runId' => $workspace->runId(),
                'projectId' => $workspace->projectId(),
                'status' => $workspace->status(),
                'createdAtUtc' => $workspace->createdAtUtc(),
                'updatedAtUtc' => $workspace->updatedAtUtc(),
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $b['updatedAtUtc'], (string) $a['updatedAtUtc'])
        );

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workspace(string $workspaceId): ?array
    {
        if (!$this->workspaces->exists($workspaceId)) {
            return null;
        }
        $workspace = $this->workspaces->get($workspaceId);
        $manifest = $this->artifacts->manifest($workspaceId);
        $listed = [];
        foreach ($this->artifacts->list($workspaceId) as $artifact) {
            $listed[] = [
                'artifactId' => $artifact->artifactId(),
                'kind' => $artifact->kind()->toString(),
                'name' => $artifact->name(),
                'contentType' => $artifact->contentType(),
                'byteSize' => $artifact->byteSize(),
                'contentHash' => $artifact->contentHash(),
                'createdAtUtc' => $artifact->createdAtUtc(),
                'persistent' => $artifact->persistent(),
            ];
        }

        return [
            'workspaceId' => $workspace->workspaceId(),
            'missionId' => $workspace->missionId(),
            'runId' => $workspace->runId(),
            'projectId' => $workspace->projectId(),
            'status' => $workspace->status(),
            'createdAtUtc' => $workspace->createdAtUtc(),
            'updatedAtUtc' => $workspace->updatedAtUtc(),
            'manifestStatus' => $manifest->status(),
            'artifacts' => $listed,
        ];
    }

    /**
     * @return array{artifact: array<string, mixed>, contents: string}|null
     */
    public function content(string $workspaceId, string $artifactId): ?array
    {
        if (!$this->workspaces->exists($workspaceId)) {
            return null;
        }
        try {
            $artifact = $this->artifacts->get($workspaceId, $artifactId);
            $contents = $this->artifacts->readContents($workspaceId, $artifactId);
        } catch (\Throwable) {
            return null;
        }

        return [
            'artifact' => [
                'artifactId' => $artifact->artifactId(),
                'kind' => $artifact->kind()->toString(),
                'name' => $artifact->name(),
                'contentType' => $artifact->contentType(),
                'byteSize' => $artifact->byteSize(),
                'contentHash' => $artifact->contentHash(),
                'createdAtUtc' => $artifact->createdAtUtc(),
            ],
            'contents' => $contents,
        ];
    }
}
