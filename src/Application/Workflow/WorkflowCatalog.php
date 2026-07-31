<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

use Aep\Application\MissionEngine\MissionStep;
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
 * Built-in task type catalog with stable identifiers.
 */
final class WorkflowCatalog
{
    public const DEFINE_SCOPE = 'mission.define_scope';
    public const START_INSPECTION = 'mission.start_inspection';
    public const SUBMIT_INSPECTION = 'mission.submit_inspection';
    public const APPROVE_INSPECTION = 'mission.approve_inspection';
    public const FINISH_IMPLEMENTATION = 'mission.finish_implementation';
    public const APPROVE_COMMIT = 'mission.approve_commit';
    public const MARK_PR_READY = 'mission.mark_pr_ready';
    public const COMPLETE = 'mission.complete';
    public const EXECUTION_RUN = 'execution.run';
    public const VALIDATION_RUN = 'validation.run';
    public const GATE_MANUAL = 'gate.manual';

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return [
            self::DEFINE_SCOPE,
            self::START_INSPECTION,
            self::SUBMIT_INSPECTION,
            self::APPROVE_INSPECTION,
            self::FINISH_IMPLEMENTATION,
            self::APPROVE_COMMIT,
            self::MARK_PR_READY,
            self::COMPLETE,
            self::EXECUTION_RUN,
            self::VALIDATION_RUN,
            self::GATE_MANUAL,
        ];
    }

    public function has(string $type): bool
    {
        return in_array($type, $this->types(), true);
    }

    /**
     * @param array<string, mixed> $with Resolved task inputs
     */
    public function createStep(WorkflowTaskDefinition $task, array $with = []): MissionStep
    {
        return match ($task->type()) {
            self::DEFINE_SCOPE => new DefineScopeStep(),
            self::START_INSPECTION => new StartInspectionStep(),
            self::SUBMIT_INSPECTION => new SubmitInspectionStep(),
            self::APPROVE_INSPECTION => new ApproveInspectionStep(),
            self::FINISH_IMPLEMENTATION => new FinishImplementationStep(),
            self::APPROVE_COMMIT => new ApproveCommitStep(),
            self::MARK_PR_READY => new MarkPrReadyStep(),
            self::COMPLETE => new CompleteMissionStep(),
            self::EXECUTION_RUN => new ExecuteImplementationStep(),
            self::VALIDATION_RUN => new RunValidationStep(),
            self::GATE_MANUAL => $this->manualGate($task, $with),
            default => throw new \InvalidArgumentException('Unknown workflow task type: ' . $task->type()),
        };
    }

    /**
     * Flatten resolved `with` into MissionContext attribute keys known by steps.
     *
     * @param array<string, mixed> $with
     * @return array<string, mixed>
     */
    public function attributesFromWith(string $type, array $with): array
    {
        $attrs = [];
        if (isset($with['allowedPaths'])) {
            $attrs['allowedPaths'] = $with['allowedPaths'];
            $attrs['declaredPaths'] = $with['allowedPaths'];
        }
        if (isset($with['declaredPaths'])) {
            $attrs['declaredPaths'] = $with['declaredPaths'];
        }
        if (isset($with['nonGoals'])) {
            $attrs['nonGoals'] = $with['nonGoals'];
        }
        if (isset($with['summary']) && is_string($with['summary'])) {
            $attrs['inspectionSummary'] = $with['summary'];
        }
        if (isset($with['action']) && is_string($with['action'])) {
            $attrs['executionAction'] = $with['action'];
        }
        if ($type === self::GATE_MANUAL && isset($with['gateId']) && is_string($with['gateId'])) {
            // gate decision still supplied at runtime via gate.{id}
            $attrs['gateId.' . $with['gateId']] = true;
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $with
     */
    private function manualGate(WorkflowTaskDefinition $task, array $with): MissionStep
    {
        $gateId = $with['gateId'] ?? $task->id();
        if (!is_string($gateId) || trim($gateId) === '') {
            throw new \InvalidArgumentException('gate.manual requires with.gateId.');
        }
        $name = $task->name() ?? ('Manual gate: ' . $gateId);

        return new ManualGateStep(trim($gateId), $name);
    }
}
