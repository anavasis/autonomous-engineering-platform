<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\MissionControl\Catalog\ProjectCatalog;
use Aep\Application\MissionControl\Catalog\RunCatalog;
use Aep\Application\MissionControl\Support\MissionSerializer;
use Aep\Application\MissionControl\Support\ProjectSerializer;
use Aep\Application\MissionEngine\MissionCheckpoint;

final class ProjectQueryService
{
    public function __construct(
        private readonly ProjectCatalog $projects,
        private readonly RunCatalog $runs,
        private readonly MissionQueryService $missions,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $items = [];
        foreach ($this->projects->all() as $project) {
            $items[] = $this->serialize($project->id()->toString());
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
    public function get(string $projectId): ?array
    {
        if ($this->projects->find($projectId) === null) {
            return null;
        }

        return $this->serialize($projectId);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(string $projectId): array
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new \RuntimeException('Project not found: ' . $projectId);
        }

        $lastRun = $this->latestRunForProject($projectId);
        $lastMission = null;
        $workflow = null;
        if ($lastRun !== null) {
            $mission = $this->missions->get($lastRun->missionId());
            if ($mission !== null) {
                $lastMission = [
                    'id' => $mission['id'],
                    'state' => $mission['state'],
                    'objective' => $mission['objective'],
                    'updatedAtUtc' => $lastRun->updatedAtUtc(),
                ];
            }
            $workflow = is_string($lastRun->attributes()['workflowId'] ?? null)
                ? $lastRun->attributes()['workflowId']
                : null;
        }

        return ProjectSerializer::summary($project, $lastMission, $workflow);
    }

    private function latestRunForProject(string $projectId): ?MissionCheckpoint
    {
        $matches = [];
        foreach ($this->runs->allCheckpoints() as $checkpoint) {
            if ($checkpoint->projectId() === $projectId) {
                $matches[] = $checkpoint;
            }
        }
        if ($matches === []) {
            return null;
        }
        usort(
            $matches,
            static fn (MissionCheckpoint $a, MissionCheckpoint $b): int => strcmp($b->updatedAtUtc(), $a->updatedAtUtc())
        );

        return $matches[0];
    }
}
