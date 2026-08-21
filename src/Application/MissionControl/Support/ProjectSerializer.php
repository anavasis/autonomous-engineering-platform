<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Support;

use Aep\Domain\Project\Project;

final class ProjectSerializer
{
    /**
     * @param array<string, mixed>|null $lastMission
     * @return array<string, mixed>
     */
    public static function summary(Project $project, ?array $lastMission = null, ?string $activeWorkflow = null): array
    {
        $binding = $project->repositoryBinding();

        return [
            'id' => $project->id()->toString(),
            'slug' => $project->slug()->toString(),
            'displayName' => $project->displayName(),
            'description' => $project->description(),
            'status' => $project->status()->toString(),
            'createdAtUtc' => $project->createdAtUtc(),
            'updatedAtUtc' => $project->updatedAtUtc(),
            'missionCount' => $project->missionCount(),
            'repository' => $binding === null ? null : [
                'provider' => $binding->provider(),
                'repository' => $binding->repository(),
            ],
            'repositoryStatus' => $binding === null ? 'unbound' : 'bound',
            'lastMission' => $lastMission,
            'activeWorkflow' => $activeWorkflow,
            'environmentCount' => count($project->environments()),
            'serverCount' => count($project->servers()),
        ];
    }
}
