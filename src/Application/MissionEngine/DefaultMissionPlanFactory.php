<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

use Aep\Application\MissionEngine\Step\ApproveCommitStep;
use Aep\Application\MissionEngine\Step\ApproveInspectionStep;
use Aep\Application\MissionEngine\Step\CompleteMissionStep;
use Aep\Application\MissionEngine\Step\DefineScopeStep;
use Aep\Application\MissionEngine\Step\ExecuteImplementationStep;
use Aep\Application\MissionEngine\Step\FinishImplementationStep;
use Aep\Application\MissionEngine\Step\ManualGateStep;
use Aep\Application\MissionEngine\Step\MarkPrReadyStep;
use Aep\Application\MissionEngine\Step\RunValidationStep;
use Aep\Application\MissionEngine\Step\StartInspectionStep;
use Aep\Application\MissionEngine\Step\SubmitInspectionStep;

/**
 * Fixed linear Mission plan for ORCH-R9 MVP. No dynamic planning.
 */
final class DefaultMissionPlanFactory
{
    public function build(MissionContext $context): MissionPlan
    {
        unset($context); // plan is fixed; context reserved for future factories

        return new MissionPlan([
            new DefineScopeStep(),
            new StartInspectionStep(),
            new SubmitInspectionStep(),
            new ManualGateStep('inspection', 'Manual gate: inspection'),
            new ApproveInspectionStep(),
            new ExecuteImplementationStep(),
            new FinishImplementationStep(),
            new RunValidationStep(),
            new ManualGateStep('commit', 'Manual gate: commit'),
            new ApproveCommitStep(),
            new MarkPrReadyStep(),
            new CompleteMissionStep(),
        ]);
    }
}
