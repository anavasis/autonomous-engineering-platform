<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\SubmitInspection;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class SubmitInspectionStep implements MissionStep
{
    public function id(): string
    {
        return 'submit_inspection';
    }

    public function name(): string
    {
        return 'Submit inspection';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $summary = $context->attribute('inspectionSummary', 'Inspection package ready.');
            if (!is_string($summary) || trim($summary) === '') {
                $summary = 'Inspection package ready.';
            }

            $context->missions()->submitInspection(new SubmitInspection(
                $context->missionId(),
                $summary,
                $context->occurredAtUtc()
            ));

            return StepResult::succeeded('Inspection submitted.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
