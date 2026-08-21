<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\MissionControl\Catalog\MissionCatalog;
use Aep\Application\MissionControl\Catalog\RunCatalog;
use Aep\Application\MissionControl\Health\HealthService;
use Aep\Domain\Mission\ValueObject\MissionState;

final class DashboardQueryService
{
    public function __construct(
        private readonly MissionCatalog $missions,
        private readonly RunCatalog $runs,
        private readonly MissionQueryService $missionQuery,
        private readonly ApprovalQueryService $approvals,
        private readonly HealthService $health,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $active = 0;
        $completed = 0;
        $failed = 0;
        $activity = [];

        foreach ($this->missions->all() as $mission) {
            $state = $mission->state()->toString();
            $run = $this->missionQuery->latestRun($mission->id()->toString());

            if ($state === MissionState::COMPLETED) {
                $completed++;
            } elseif ($this->missionQuery->isFailedRun($run)) {
                $failed++;
            } elseif ($state !== MissionState::COMPLETED) {
                $active++;
            }

            $activity[] = [
                'at' => $run?->updatedAtUtc() ?? $mission->createdAtUtc(),
                'type' => 'mission',
                'missionId' => $mission->id()->toString(),
                'message' => $run?->message() !== '' && $run !== null
                    ? $run->message()
                    : 'Mission ' . $state,
                'state' => $state,
                'engineState' => $run?->engineState(),
            ];
        }

        usort(
            $activity,
            static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at'])
        );

        return [
            'activeMissions' => $active,
            'completedMissions' => $completed,
            'failedMissions' => $failed,
            'waitingApprovals' => count($this->approvals->list()),
            'recentActivity' => array_slice($activity, 0, 20),
            'systemHealth' => $this->health->probe(),
            'runCount' => count($this->runs->allCheckpoints()),
        ];
    }
}
