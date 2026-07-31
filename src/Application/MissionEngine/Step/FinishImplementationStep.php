<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class FinishImplementationStep implements MissionStep
{
    public function id(): string
    {
        return 'finish_implementation';
    }

    public function name(): string
    {
        return 'Finish implementation';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $context->missions()->finishImplementation(new TimedMissionCommand(
                $context->missionId(),
                $context->occurredAtUtc()
            ));

            return StepResult::succeeded('Implementation finished.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
