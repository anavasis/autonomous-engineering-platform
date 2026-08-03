<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\ExecutionRuntime\Handler\MissionExecutionJobHandler;
use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Service\JobDispatcher;
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
 * When a JobDispatcher is wired, MissionEngine runs asynchronously via Runtime.
 */
final class LaunchFacade
{
    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly MissionEngine $engine,
        private readonly ?JobDispatcher $runtime = null,
    ) {
    }

    /**
     * @return array{missionId: string, runId: string, engineState: string, message: string, jobId?: string}
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

        $branch = $params['branch'] ?? ($attributes['branch'] ?? null);

        return $this->dispatchStart(
            $runId,
            $missionId,
            'user',
            $actor->id(),
            $attributes,
            $plan->projectId(),
            array_filter([
                'provider' => $provider,
                'repository' => $repository,
                'branch' => is_string($branch) ? $branch : null,
                'requestedBy' => $actor->id(),
                'planId' => $plan->id(),
                'projectId' => $plan->projectId(),
            ], static fn ($v) => $v !== null && $v !== ''),
        );
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

        return $this->dispatchStart(
            $runId,
            $missionId,
            'user',
            $actor->id(),
            $attributes,
            $projectId,
            [
                'requestedBy' => $actor->id(),
                'projectId' => $projectId,
                'retry' => true,
            ],
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function resume(string $runId, array $attributes = []): array
    {
        if ($this->runtime === null) {
            $result = $this->engine->resume($runId, $attributes, Utc::now());

            return [
                'runId' => $result->runId(),
                'missionId' => $result->missionId(),
                'engineState' => $result->engineState()->toString(),
                'message' => $result->message(),
            ];
        }

        $job = $this->runtime->enqueue(
            MissionExecutionJobHandler::TYPE,
            [
                'action' => 'resume',
                'runId' => $runId,
                'attributes' => $attributes,
                'occurredAtUtc' => Utc::now(),
            ],
            null,
            JobPriority::HIGH,
            [
                'requestedBy' => is_string($attributes['requestedBy'] ?? null) ? $attributes['requestedBy'] : 'system',
                'runId' => $runId,
            ],
        );

        return [
            'runId' => $runId,
            'missionId' => is_string($attributes['missionId'] ?? null) ? $attributes['missionId'] : '',
            'engineState' => 'planned',
            'message' => 'Mission resume enqueued.',
            'jobId' => $job->id(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $metadata
     * @return array{missionId: string, runId: string, engineState: string, message: string, jobId?: string}
     */
    private function dispatchStart(
        string $runId,
        string $missionId,
        string $actorType,
        string $actorId,
        array $attributes,
        ?string $projectId,
        array $metadata = [],
    ): array {
        if ($this->runtime === null) {
            $result = $this->engine->start(new MissionEngineRequest(
                $runId,
                $missionId,
                Utc::now(),
                $actorType,
                $actorId,
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

        $meta = $metadata;
        $meta['runId'] = $runId;
        $meta['missionId'] = $missionId;
        $meta['requestedBy'] = $meta['requestedBy'] ?? $actorId;

        $job = $this->runtime->enqueue(
            MissionExecutionJobHandler::TYPE,
            [
                'action' => 'start',
                'runId' => $runId,
                'missionId' => $missionId,
                'actorType' => $actorType,
                'actorId' => $actorId,
                'projectId' => $projectId,
                'attributes' => $attributes,
                'occurredAtUtc' => Utc::now(),
            ],
            null,
            JobPriority::NORMAL,
            $meta,
        );

        return [
            'missionId' => $missionId,
            'runId' => $runId,
            'engineState' => 'planned',
            'message' => 'Mission execution enqueued.',
            'jobId' => $job->id(),
        ];
    }
}
