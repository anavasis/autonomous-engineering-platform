<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Port\ProjectMemoryRepository;

/**
 * Stores project-specific engineering knowledge after missions — not chat memory.
 */
final class EngineeringMemoryService
{
    public function __construct(
        private readonly ProjectMemoryRepository $memory,
    ) {
    }

    public function recordPlanPatterns(ExecutionPlan $plan, MissionIntent $intent): void
    {
        $projectId = $plan->projectId();
        if ($projectId === null || $projectId === '') {
            return;
        }
        $mem = $this->memory->getOrCreate($projectId);
        $at = Utc::now();

        foreach ($intent->affectedAreas() as $area) {
            foreach ($plan->parameters()['allowedPaths'] ?? [] as $path) {
                if (is_string($path) && $path !== '') {
                    $mem->putAlias($area, $path, $at);
                    break;
                }
            }
        }

        foreach ($plan->constraints() as $c) {
            if (str_starts_with(strtolower($c), 'do not')) {
                $mem->rememberConstraint($c, $at);
            }
        }

        $mem->rememberPattern(
            'Workflow ' . $plan->workflowId() . '@' . $plan->workflowVersion() . ' for ' . $intent->changeType() . ' missions',
            $at
        );
        $this->memory->save($mem);
    }

    public function recordCompletion(string $projectId, string $objective, bool $succeeded, array $constraints = []): void
    {
        if ($projectId === '') {
            return;
        }
        $mem = $this->memory->getOrCreate($projectId);
        $at = Utc::now();
        if ($succeeded) {
            $mem->rememberLesson('Completed: ' . $objective, $at);
            $mem->rememberPattern('Success pattern for: ' . $objective, $at);
        } else {
            $mem->rememberLesson('Failed/incomplete: ' . $objective . ' — review constraints and scope.', $at);
        }
        foreach ($constraints as $c) {
            if (is_string($c) && $c !== '') {
                $mem->rememberConstraint($c, $at);
            }
        }
        $mem->rememberKnowledge('Last mission objective: ' . $objective, $at);
        $this->memory->save($mem);
    }
}
