<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class MarkPrReadyStep implements MissionStep
{
    public function id(): string
    {
        return 'mark_pr_ready';
    }

    public function name(): string
    {
        return 'Mark PR ready';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $context->missions()->markPrReady(new TimedMissionCommand(
                $context->missionId(),
                $context->occurredAtUtc()
            ));

            return StepResult::succeeded('PR marked ready.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
