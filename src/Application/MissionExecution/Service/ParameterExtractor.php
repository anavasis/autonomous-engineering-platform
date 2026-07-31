<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Model\ProjectMemory;

final class ParameterExtractor
{
    /**
     * @return array{
     *   parameters: array<string, mixed>,
     *   constraints: list<string>,
     *   tests: list<string>,
     *   approvals: array<string, mixed>,
     *   whyFiles: string,
     *   whyTests: string,
     *   whyApprovals: string
     * }
     */
    public function extract(MissionIntent $intent, ?ProjectMemory $memory): array
    {
        $allowed = $intent->allowedPaths();
        if ($allowed === []) {
            $allowed = $memory?->defaultAllowedPaths() ?? ['src/'];
        }
        $nonGoals = $intent->nonGoals();
        foreach ($intent->forbiddenAreas() as $f) {
            if (!in_array($f, $nonGoals, true)) {
                $nonGoals[] = 'Do not modify ' . $f;
            }
        }

        $executionAction = match ($intent->changeType()) {
            'feature' => 'implement',
            'refactor' => 'refactor',
            'inspect' => 'inspect',
            default => 'implement',
        };

        $tests = $intent->testExpectations();
        if ($tests === []) {
            $tests = ['Run existing validation pipeline', 'Do not weaken coverage for touched paths'];
        }

        $approvals = [
            'inspection' => $intent->inspectionFirst() ? 'required' : 'required',
            'implementation' => 'none',
            'commit' => 'required',
            'mode' => $intent->approvalMode(),
        ];

        $constraints = [];
        foreach ($allowed as $p) {
            $constraints[] = 'Allowed: ' . $p;
        }
        foreach ($nonGoals as $ng) {
            $constraints[] = $ng;
        }

        $whyFiles = $allowed === ['src/']
            ? 'Defaulted to src/ because no more specific path was resolved from intent or project memory.'
            : 'Paths derived from affected areas, aliases in project memory, and/or explicit constraints in the request.';

        $whyTests = count($intent->testExpectations()) > 0
            ? 'Tests requested explicitly in the mission text.'
            : 'Default validation expectations for a controlled change under aep.default_mission.';

        $whyApprovals = $intent->inspectionFirst()
            ? 'Inspection-first was requested or implied; inspection and commit (merge) gates remain required.'
            : 'Standard approval mode: inspection and commit gates required by default mission workflow.';

        return [
            'parameters' => [
                'allowedPaths' => $allowed,
                'nonGoals' => $nonGoals,
                'executionAction' => $executionAction,
            ],
            'constraints' => $constraints,
            'tests' => $tests,
            'approvals' => $approvals,
            'whyFiles' => $whyFiles,
            'whyTests' => $whyTests,
            'whyApprovals' => $whyApprovals,
        ];
    }
}
