<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class ApproveInspectionStep implements MissionStep
{
    public function id(): string
    {
        return 'approve_inspection';
    }

    public function name(): string
    {
        return 'Approve inspection';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $context->missions()->approveInspection(new ApprovalCommand(
                $context->missionId(),
                $context->actorType(),
                $context->actorId(),
                $context->occurredAtUtc(),
                'engine gate approved'
            ));

            return StepResult::succeeded('Inspection approved.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
