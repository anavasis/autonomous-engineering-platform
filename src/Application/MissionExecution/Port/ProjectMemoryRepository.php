<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Port;

use Aep\Application\MissionExecution\Model\ProjectMemory;

interface ProjectMemoryRepository
{
    public function save(ProjectMemory $memory): void;

    public function find(string $projectId): ?ProjectMemory;

    public function getOrCreate(string $projectId): ProjectMemory;
}
