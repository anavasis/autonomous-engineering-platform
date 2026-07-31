<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Port;

use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Model\ProjectMemory;

interface PromptUnderstandingPort
{
    /**
     * @param list<array<string, mixed>> $projectSummaries
     */
    public function understand(
        string $text,
        ?string $projectIdHint,
        array $projectSummaries,
        ?ProjectMemory $memory,
    ): MissionIntent;
}
