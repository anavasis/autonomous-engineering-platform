<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class StartInspectionStep implements MissionStep
{
    public function id(): string
    {
        return 'start_inspection';
    }

    public function name(): string
    {
        return 'Start inspection';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $context->missions()->startInspection(new TimedMissionCommand(
                $context->missionId(),
                $context->occurredAtUtc()
            ));

            return StepResult::succeeded('Inspection started.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
