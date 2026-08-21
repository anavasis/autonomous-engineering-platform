<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Port;

use Aep\Application\MissionExecution\Model\MissionIntake;

interface IntakeRepository
{
    public function save(MissionIntake $intake): void;

    public function find(string $id): ?MissionIntake;

    public function findByClientRequestId(string $clientRequestId): ?MissionIntake;

    public function findByMissionId(string $missionId): ?MissionIntake;
}
