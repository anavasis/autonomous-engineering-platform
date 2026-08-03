<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Planning\Adapter;

use Aep\Application\ExecutionRuntime\Handler\MissionExecutionJobHandler;
use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Service\JobDispatcher;
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
 * Scheduler admission stays synchronous; MissionEngine drive is enqueued when Runtime is wired.
 */
final class PlanningLaunchAdapter implements PlanningLaunchPort
{
    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly MissionEngine $engine,
        private readonly ?JobDispatcher $runtime = null,
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

        if ($this->runtime === null) {
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

        $job = $this->runtime->enqueue(
            MissionExecutionJobHandler::TYPE,
            [
                'action' => 'start',
                'runId' => $runId,
                'missionId' => $missionId,
                'actorType' => 'system',
                'actorId' => $actorId,
                'projectId' => $program->projectId(),
                'attributes' => $attributes,
                'occurredAtUtc' => $at,
            ],
            null,
            JobPriority::NORMAL,
            array_filter([
                'provider' => 'github',
                'repository' => 'local/planning-program',
                'requestedBy' => $actorId,
                'programId' => $program->programId(),
                'programNodeId' => $node->nodeId(),
                'projectId' => $program->projectId(),
                'runId' => $runId,
                'missionId' => $missionId,
            ], static fn ($v) => $v !== null && $v !== ''),
        );

        return [
            'missionId' => $missionId,
            'runId' => $runId,
            'message' => 'Mission execution enqueued.',
            'jobId' => $job->id(),
        ];
    }
}
