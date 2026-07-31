<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Model\MissionIntake;

/**
 * Launches missions exclusively through existing Application services.
 */
final class LaunchFacade
{
    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly MissionEngine $engine,
    ) {
    }

    /**
     * @return array{missionId: string, runId: string, engineState: string, message: string}
     */
    public function launch(User $actor, MissionIntake $intake, ExecutionPlan $plan): array
    {
        if ($intake->status() !== MissionIntake::STATUS_READY && $intake->status() !== MissionIntake::STATUS_LAUNCHED) {
            throw new \InvalidArgumentException('Intake must be ready before launch.');
        }

        $missionId = 'msn_' . bin2hex(random_bytes(6));
        $runId = 'run_' . bin2hex(random_bytes(6));
        $provider = $plan->provider() ?? 'github';
        $repository = $plan->repository();
        if ($repository === null || $repository === '') {
            throw new \InvalidArgumentException('Execution plan is missing target repository.');
        }

        $this->missions->create(new CreateMission(
            $missionId,
            $provider,
            $repository,
            $plan->objective(),
            'user',
            $actor->id(),
            Utc::now()
        ));

        $params = $plan->parameters();
        $allowed = is_array($params['allowedPaths'] ?? null) ? $params['allowedPaths'] : ['src/'];
        $nonGoals = is_array($params['nonGoals'] ?? null) ? $params['nonGoals'] : [];
        /** @var list<string> $allowedPaths */
        $allowedPaths = [];
        foreach ($allowed as $p) {
            if (is_string($p) && $p !== '') {
                $allowedPaths[] = $p;
            }
        }
        if ($allowedPaths === []) {
            $allowedPaths = ['src/'];
        }
        /** @var list<string> $nonGoalList */
        $nonGoalList = [];
        foreach ($nonGoals as $ng) {
            if (is_string($ng)) {
                $nonGoalList[] = $ng;
            }
        }

        $this->missions->defineScope(new DefineScope($missionId, $allowedPaths, $nonGoalList));

        $attributes = $plan->launchAttributes();
        $attributes['conversationId'] = $intake->conversationId();
        $attributes['planId'] = $plan->id();
        $attributes['confirmedBy'] = $actor->id();

        $result = $this->engine->start(new MissionEngineRequest(
            $runId,
            $missionId,
            Utc::now(),
            'user',
            $actor->id(),
            $attributes,
            $plan->projectId()
        ));

        return [
            'missionId' => $missionId,
            'runId' => $runId,
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function retry(User $actor, string $missionId, ?string $projectId, array $attributes = []): array
    {
        $runId = 'run_' . bin2hex(random_bytes(6));
        $attributes['retryOf'] = $attributes['priorRunId'] ?? null;
        unset($attributes['priorRunId']);
        $result = $this->engine->start(new MissionEngineRequest(
            $runId,
            $missionId,
            Utc::now(),
            'user',
            $actor->id(),
            $attributes,
            $projectId
        ));

        return [
            'missionId' => $missionId,
            'runId' => $runId,
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function resume(string $runId, array $attributes = []): array
    {
        $result = $this->engine->resume($runId, $attributes, Utc::now());

        return [
            'runId' => $result->runId(),
            'missionId' => $result->missionId(),
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
        ];
    }
}
