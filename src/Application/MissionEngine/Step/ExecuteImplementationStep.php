<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class ExecuteImplementationStep implements MissionStep
{
    public function id(): string
    {
        return 'execute_implementation';
    }

    public function name(): string
    {
        return 'Execute implementation';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $action = $context->attribute('executionAction', 'implement');
            if (!is_string($action) || trim($action) === '') {
                $action = 'implement';
            }

            $result = $context->requireExecution()->execute(new ExecutionRequest(
                $context->missionId(),
                $action,
                $context->occurredAtUtc(),
                ['runId' => $context->runId()]
            ));

            if ($result->isSucceeded()) {
                return StepResult::succeeded($result->message() !== '' ? $result->message() : 'Implementation executed.');
            }
            if ($result->isRejected()) {
                return StepResult::rejected($result->message());
            }

            // Executor failures are retryable by default for MVP.
            return StepResult::failed($result->message(), true);
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage(), true);
        }
    }
}
