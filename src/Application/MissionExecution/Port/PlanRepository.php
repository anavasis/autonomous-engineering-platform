<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Port;

use Aep\Application\MissionExecution\Model\ExecutionPlan;

interface PlanRepository
{
    public function save(ExecutionPlan $plan): void;

    public function find(string $id): ?ExecutionPlan;

    public function findByIntakeId(string $intakeId): ?ExecutionPlan;

    public function findByMissionId(string $missionId): ?ExecutionPlan;
}
