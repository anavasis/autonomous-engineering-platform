<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\MissionControl\Catalog\MissionCatalog;
use Aep\Application\MissionControl\Catalog\RunCatalog;
use Aep\Application\MissionControl\Support\MissionSerializer;
use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunState;

final class MissionQueryService
{
    public function __construct(
        private readonly MissionCatalog $missions,
        private readonly RunCatalog $runs,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $state = null): array
    {
        $items = [];
        foreach ($this->missions->all() as $mission) {
            if ($state !== null && !$mission->state()->is($state)) {
                continue;
            }
            $run = $this->latestRun($mission->id()->toString());
            $items[] = MissionSerializer::summary($mission, $run);
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp((string) $b['createdAtUtc'], (string) $a['createdAtUtc'])
        );

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $missionId): ?array
    {
        $mission = $this->missions->find($missionId);
        if ($mission === null) {
            return null;
        }
        $run = $this->latestRun($missionId);
        $timeline = $run === null ? null : $this->runs->findTimeline($run->runId());

        return MissionSerializer::detail($mission, $run, $timeline);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(string $missionId, ?string $runId = null): array
    {
        $run = $runId !== null
            ? $this->runs->findCheckpoint($runId)
            : $this->latestRun($missionId);
        if ($run === null || $run->missionId() !== $missionId) {
            return [];
        }
        $timeline = $this->runs->findTimeline($run->runId());
        if ($timeline === null) {
            return [];
        }

        return array_map(static fn ($e) => $e->toArray(), $timeline->entries());
    }

    public function latestRun(string $missionId): ?MissionCheckpoint
    {
        $runs = $this->runs->checkpointsForMission($missionId);
        if ($runs === []) {
            return null;
        }
        usort(
            $runs,
            static fn (MissionCheckpoint $a, MissionCheckpoint $b): int => strcmp($b->updatedAtUtc(), $a->updatedAtUtc())
        );

        return $runs[0];
    }

    public function isFailedRun(?MissionCheckpoint $run): bool
    {
        if ($run === null) {
            return false;
        }

        return in_array($run->engineState(), [
            MissionRunState::FAILED,
            MissionRunState::TIMED_OUT,
        ], true);
    }

    public function isActiveRun(?MissionCheckpoint $run): bool
    {
        if ($run === null) {
            return false;
        }

        return in_array($run->engineState(), [
            MissionRunState::PLANNED,
            MissionRunState::RUNNING,
            MissionRunState::WAITING,
            MissionRunState::SUSPENDED,
        ], true);
    }
}
