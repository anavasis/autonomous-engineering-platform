<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Command;

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
 */
final class MissionControlCommandFacade
{
    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly ProjectCommandService $projects,
        private readonly MissionEngine $engine,
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
        $result = $this->engine->resume($runId, [
            $key => $approve ? 'approved' : 'rejected',
            'decidedBy' => $actor->id(),
        ], Utc::now());

        return [
            'runId' => $result->runId(),
            'missionId' => $result->missionId(),
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
            'gateId' => $gateId,
            'decision' => $approve ? 'approved' : 'rejected',
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

    /**
     * @return array<string, mixed>
     */
    public function cancelRun(string $runId, string $reason = 'Cancelled from Mission Control.'): array
    {
        $result = $this->engine->cancel($runId, $reason, Utc::now());

        return [
            'runId' => $result->runId(),
            'missionId' => $result->missionId(),
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
        ];
    }
}
