<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\RecordValidation;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;
use Aep\Application\Validation\ValidationRequest;

/**
 * Runs ValidationPipeline then records outcome via MissionCommandService.
 */
final class RunValidationStep implements MissionStep
{
    public function id(): string
    {
        return 'run_validation';
    }

    public function name(): string
    {
        return 'Run validation';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $paths = $context->attribute('declaredPaths', $context->attribute('allowedPaths', ['src/']));
            if (!is_array($paths) || $paths === []) {
                $paths = ['src/'];
            }

            $validationContext = [
                'runId' => $context->runId(),
                'declaredPaths' => $paths,
                'allowedPaths' => $paths,
            ];
            foreach ([
                'providerId',
                'routedProviderId',
                'sessionId',
                'workspacePath',
                'filesChanged',
                'patchId',
                'patchStatus',
                'mergeReady',
                'artifacts',
            ] as $key) {
                $value = $context->attribute($key);
                if ($value !== null) {
                    $validationContext[$key] = $value;
                }
            }

            $report = $context->requireValidation()->run(new ValidationRequest(
                $context->missionId(),
                $context->occurredAtUtc(),
                $validationContext
            ));

            $context->missions()->recordValidation(new RecordValidation(
                $context->missionId(),
                $report->outcome(),
                $report->reason(),
                $context->occurredAtUtc()
            ));

            if ($report->isPassed()) {
                return StepResult::succeeded($report->reason(), [
                    'validationOutcome' => $report->outcome(),
                    'validationReason' => $report->reason(),
                ]);
            }

            return StepResult::failed($report->reason(), false, [
                'validationOutcome' => $report->outcome(),
                'validationReason' => $report->reason(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
