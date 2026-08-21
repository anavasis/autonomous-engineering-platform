<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Catalog;

use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionTimeline;

interface RunCatalog
{
    /**
     * @return list<MissionCheckpoint>
     */
    public function allCheckpoints(): array;

    /**
     * @return list<MissionCheckpoint>
     */
    public function checkpointsForMission(string $missionId): array;

    public function findCheckpoint(string $runId): ?MissionCheckpoint;

    public function findTimeline(string $runId): ?MissionTimeline;
}
