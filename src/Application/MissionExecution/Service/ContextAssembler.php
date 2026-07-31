<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionControl\Catalog\MissionCatalog;
use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Model\ProjectMemory;
use Aep\Application\MissionExecution\Port\ProjectMemoryRepository;

final class ContextAssembler
{
    public function __construct(
        private readonly MissionCatalog $missions,
        private readonly ProjectMemoryRepository $memory,
    ) {
    }

    /**
     * @return array{
     *   memory: ?ProjectMemory,
     *   contextRefs: list<string>,
     *   priorMissionSummaries: list<string>
     * }
     */
    public function assemble(MissionIntent $intent): array
    {
        $projectId = $intent->projectId();
        $memory = $projectId !== null ? $this->memory->getOrCreate($projectId) : null;
        $refs = [];
        $priors = [];

        if ($projectId !== null) {
            $refs[] = 'memory:' . $projectId;
        }

        $objectiveTokens = $this->tokens($intent->objective());
        foreach ($this->missions->all() as $mission) {
            if ($objectiveTokens === []) {
                break;
            }
            $score = 0;
            foreach ($objectiveTokens as $token) {
                if (str_contains(strtolower($mission->brief()->objective()), $token)) {
                    $score++;
                }
            }
            if ($score >= 2) {
                $id = $mission->id()->toString();
                $refs[] = 'mission:' . $id;
                $priors[] = $id . ' [' . $mission->state()->toString() . '] ' . $mission->brief()->objective();
            }
            if (count($priors) >= 5) {
                break;
            }
        }

        return [
            'memory' => $memory,
            'contextRefs' => $refs,
            'priorMissionSummaries' => $priors,
        ];
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $parts = preg_split('/\W+/', strtolower($text)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (strlen($p) >= 4) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
