<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Command;

use Aep\Application\ExecutionRuntime\Handler\MissionExecutionJobHandler;
use Aep\Application\ExecutionRuntime\Model\JobPriority;
use Aep\Application\ExecutionRuntime\Service\JobDispatcher;
use Aep\Application\ExecutionRuntime\Service\RuntimeCancellation;
use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;
use Aep\Application\Project\Command\CreateProject;
use Aep\Application\Project\ProjectCommandService;

/**
 * Thin command façade — delegates to existing Application services only.
 * Mission start/resume/cancel go through Execution Runtime when wired.
 */
final class MissionControlCommandFacade
{
    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly ProjectCommandService $projects,
        private readonly MissionEngine $engine,
        private readonly ?JobDispatcher $runtime = null,
        private readonly ?RuntimeCancellation $runtimeCancel = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createProject(
        User $actor,
        string $projectId,
        string $slug,
        string $displayName,
        string $description = '',
    ): array {
        $result = $this->projects->create(new CreateProject(
            $projectId,
            $slug,
            $displayName,
            'user',
            $actor->id(),
            Utc::now(),
            $description
        ));

        return [
            'projectId' => $result->projectId()->toString(),
            'status' => $result->status()->toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createMission(
        User $actor,
        string $missionId,
        string $provider,
        string $repository,
        string $objective,
    ): array {
        $result = $this->missions->create(new CreateMission(
            $missionId,
            $provider,
            $repository,
            $objective,
            'user',
            $actor->id(),
            Utc::now()
        ));

        return [
            'missionId' => $result->missionId()->toString(),
            'state' => $result->state()->toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decideInspection(User $actor, string $missionId, bool $approve, string $rationale = ''): array
    {
        $command = new ApprovalCommand($missionId, 'user', $actor->id(), Utc::now(), $rationale);
        $result = $approve
            ? $this->missions->approveInspection($command)
            : $this->missions->rejectInspection($command);

        return [
            'missionId' => $result->missionId()->toString(),
            'state' => $result->state()->toString(),
            'decision' => $approve ? 'approved' : 'rejected',
            'subject' => 'inspection',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decideCommit(User $actor, string $missionId, bool $approve, string $rationale = ''): array
    {
        $command = new ApprovalCommand($missionId, 'user', $actor->id(), Utc::now(), $rationale);
        $result = $approve
            ? $this->missions->approveCommit($command)
            : $this->missions->rejectCommit($command);

        return [
            'missionId' => $result->missionId()->toString(),
            'state' => $result->state()->toString(),
            'decision' => $approve ? 'approved' : 'rejected',
            'subject' => 'commit',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decideGate(User $actor, string $runId, string $gateId, bool $approve): array
    {
        $key = 'gate.' . $gateId;
        $attributes = [
            $key => $approve ? 'approved' : 'rejected',
            'decidedBy' => $actor->id(),
        ];

        if ($this->runtime === null) {
            $result = $this->engine->resume($runId, $attributes, Utc::now());

            return [
                'runId' => $result->runId(),
                'missionId' => $result->missionId(),
                'engineState' => $result->engineState()->toString(),
                'message' => $result->message(),
                'gateId' => $gateId,
                'decision' => $approve ? 'approved' : 'rejected',
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
                'requestedBy' => $actor->id(),
                'runId' => $runId,
                'gateId' => $gateId,
            ],
        );

        return [
            'runId' => $runId,
            'missionId' => '',
            'engineState' => 'planned',
            'message' => 'Mission resume enqueued.',
            'gateId' => $gateId,
            'decision' => $approve ? 'approved' : 'rejected',
            'jobId' => $job->id(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function startRun(
        User $actor,
        string $runId,
        string $missionId,
        ?string $projectId = null,
        array $attributes = [],
    ): array {
        if ($this->runtime === null) {
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
                'runId' => $result->runId(),
                'missionId' => $result->missionId(),
                'engineState' => $result->engineState()->toString(),
                'message' => $result->message(),
                'progressPercent' => $result->progressPercent(),
            ];
        }

        $job = $this->runtime->enqueue(
            MissionExecutionJobHandler::TYPE,
            [
                'action' => 'start',
                'runId' => $runId,
                'missionId' => $missionId,
                'actorType' => 'user',
                'actorId' => $actor->id(),
                'projectId' => $projectId,
                'attributes' => $attributes,
                'occurredAtUtc' => Utc::now(),
            ],
            null,
            JobPriority::NORMAL,
            array_filter([
                'requestedBy' => $actor->id(),
                'runId' => $runId,
                'missionId' => $missionId,
                'projectId' => $projectId,
            ], static fn ($v) => $v !== null && $v !== ''),
        );

        return [
            'runId' => $runId,
            'missionId' => $missionId,
            'engineState' => 'planned',
            'message' => 'Mission execution enqueued.',
            'progressPercent' => 0.0,
            'jobId' => $job->id(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelRun(string $runId, string $reason = 'Cancelled from Mission Control.'): array
    {
        if ($this->runtimeCancel !== null) {
            return $this->runtimeCancel->cancelRun($runId, $reason);
        }

        $result = $this->engine->cancel($runId, $reason, Utc::now());

        return [
            'runId' => $result->runId(),
            'missionId' => $result->missionId(),
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
        ];
    }
}
