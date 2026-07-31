<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\Workflow\DefaultMissionWorkflow;

final class MissionPlanner
{
    public function __construct(
        private readonly WorkflowSelector $workflows,
        private readonly ParameterExtractor $parameters,
        private readonly ContextAssembler $context,
    ) {
    }

    public function plan(string $intakeId, MissionIntent $intent): ExecutionPlan
    {
        $ctx = $this->context->assemble($intent);
        $selected = $this->workflows->select($intent);
        $extracted = $this->parameters->extract($intent, $ctx['memory']);
        $steps = $this->estimatedSteps();
        $duration = max(120, count($steps) * 45);

        $explain = [
            'whyWorkflow' => $selected['reason'],
            'whyFiles' => $extracted['whyFiles'],
            'whyTests' => $extracted['whyTests'],
            'whyApprovals' => $extracted['whyApprovals'],
        ];
        if ($ctx['priorMissionSummaries'] !== []) {
            $explain['priorContext'] = 'Reused signals from prior missions: ' . implode('; ', array_slice($ctx['priorMissionSummaries'], 0, 3));
        }

        $at = Utc::now();
        $planId = 'plan_' . bin2hex(random_bytes(6));

        return new ExecutionPlan(
            $planId,
            $intakeId,
            $selected['workflowId'],
            $selected['version'],
            $intent->objective(),
            $intent->projectId(),
            $intent->provider() ?? 'github',
            $intent->repository(),
            $extracted['parameters'],
            $intent->affectedAreas(),
            $extracted['constraints'],
            $extracted['tests'],
            $extracted['approvals'],
            $steps,
            $duration,
            $explain,
            $ctx['contextRefs'],
            [
                'workflowId' => $selected['workflowId'],
                'workflowVersion' => $selected['version'],
                'allowedPaths' => $extracted['parameters']['allowedPaths'],
                'nonGoals' => $extracted['parameters']['nonGoals'],
                'executionAction' => $extracted['parameters']['executionAction'],
                'intakeId' => $intakeId,
                'ameVersion' => '0.2.0',
            ],
            [
                'architect' => null,
                'developer' => null,
                'reviewer' => null,
                'tester' => null,
                'security' => null,
            ],
            $at
        );
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function estimatedSteps(): array
    {
        $json = json_decode(DefaultMissionWorkflow::json(), true);
        $tasks = is_array($json) && isset($json['tasks']) && is_array($json['tasks']) ? $json['tasks'] : [];
        $steps = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $id = is_string($task['id'] ?? null) ? $task['id'] : '';
            if ($id === '') {
                continue;
            }
            $steps[] = [
                'id' => $id,
                'name' => str_replace('_', ' ', $id),
            ];
        }

        return $steps;
    }
}
