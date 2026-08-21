<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\Execution\ExecutionRequest;

final class PromptPipeline
{
    /**
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     * @param list<string> $contextNotes
     */
    public function build(
        ExecutionRequest $request,
        array $allowedPaths,
        array $nonGoals,
        array $contextNotes = [],
    ): PromptBundle {
        $system = implode("\n", [
            'You are an autonomous engineering agent operating inside AEP Mission Control.',
            'Respect allowed paths and non-goals strictly.',
            'Do not print secrets or credentials.',
            'Produce minimal, reviewable changes.',
        ]);

        $lines = [
            'Mission: ' . $request->missionId(),
            'Action: ' . $request->action(),
            'Allowed paths: ' . implode(', ', $allowedPaths),
        ];
        if ($nonGoals !== []) {
            $lines[] = 'Non-goals / constraints:';
            foreach ($nonGoals as $ng) {
                $lines[] = '- ' . $ng;
            }
        }
        if ($contextNotes !== []) {
            $lines[] = 'Project context:';
            foreach (array_slice($contextNotes, 0, 12) as $note) {
                $lines[] = '- ' . $note;
            }
        }
        $objective = $request->contextValue('objective');
        if (is_string($objective) && $objective !== '') {
            $lines[] = 'Objective: ' . $objective;
        }

        return new PromptBundle($system, implode("\n", $lines));
    }
}
