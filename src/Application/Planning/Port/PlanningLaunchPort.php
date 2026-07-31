<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Port;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

interface PlanningLaunchPort
{
    /**
     * @return array{missionId: string, runId: string, message: string}
     */
    public function launchNode(Program $program, ProgramNode $node, string $actorId): array;
}
