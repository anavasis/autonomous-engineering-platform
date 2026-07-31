<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Planning\Adapter;

use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;

/**
 * Launches program nodes through MissionCommandService + MissionEngine only.
 * Does not modify Mission Engine semantics or Domain FSM rules.
 */
final class PlanningLaunchAdapter implements PlanningLaunchPort
{
    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly MissionEngine $engine,
    ) {
    }

    public function launchNode(Program $program, ProgramNode $node, string $actorId): array
    {
        $missionId = 'msn_' . bin2hex(random_bytes(6));
        $runId = 'run_' . bin2hex(random_bytes(6));
        $at = Utc::now();

        $this->missions->create(new CreateMission(
            $missionId,
            'github',
            'local/planning-program',
            $node->objective(),
            'system',
            $actorId,
            $at,
        ));

        $allowed = ['src/'];
        $constraints = $node->constraints();
        if (isset($constraints['allowedPaths']) && is_array($constraints['allowedPaths'])) {
            $allowed = [];
            foreach ($constraints['allowedPaths'] as $p) {
                if (is_string($p) && $p !== '') {
                    $allowed[] = $p;
                }
            }
            if ($allowed === []) {
                $allowed = ['src/'];
            }
        }
        $nonGoals = [];
        if (isset($constraints['nonGoals']) && is_array($constraints['nonGoals'])) {
            foreach ($constraints['nonGoals'] as $ng) {
                if (is_string($ng)) {
                    $nonGoals[] = $ng;
                }
            }
        }
        $this->missions->defineScope(new DefineScope($missionId, $allowed, $nonGoals));

        $attributes = [
            'programId' => $program->programId(),
            'programNodeId' => $node->nodeId(),
            'objective' => $node->objective(),
            'summary' => $node->title(),
            'planSummary' => 'Scheduled by Planning Engine for program ' . $program->programId(),
        ];
        if ($node->providerId()) {
            $attributes['providerId'] = $node->providerId();
        }
        if ($program->projectId()) {
            $attributes['projectId'] = $program->projectId();
        }

        $result = $this->engine->start(new MissionEngineRequest(
            $runId,
            $missionId,
            $at,
            'system',
            $actorId,
            $attributes,
            $program->projectId(),
        ));

        return [
            'missionId' => $missionId,
            'runId' => $runId,
            'message' => $result->message(),
        ];
    }
}
