<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class CompleteMissionStep implements MissionStep
{
    public function id(): string
    {
        return 'complete_mission';
    }

    public function name(): string
    {
        return 'Complete mission';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $context->missions()->complete(new TimedMissionCommand(
                $context->missionId(),
                $context->occurredAtUtc()
            ));

            return StepResult::succeeded('Mission completed.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
