<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramSnapshot;
use Aep\Application\Planning\Port\PlanningSettingsStore;
use Aep\Application\Planning\Port\ProgramStore;

/**
 * Application façade for program lifecycle (create/plan/start/tick/pause/replan).
 */
final class PlanningPipelineService
{
    public function __construct(
        private readonly ProgramStore $store,
        private readonly PlanningSettingsStore $settings,
        private readonly ProgramPlanner $planner,
        private readonly Scheduler $scheduler,
        private readonly Replanner $replanner,
        private readonly DependencyManager $deps,
        private readonly CriticalPathAnalyzer $criticalPath,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): Program
    {
        $at = Utc::now();
        $program = new Program(
            Program::makeId(),
            is_string($input['title'] ?? null) ? $input['title'] : 'Engineering Program',
            is_string($input['objective'] ?? null) ? $input['objective'] : '',
            Program::STATUS_DRAFT,
            $at,
            $at,
            projectId: is_string($input['projectId'] ?? null) ? $input['projectId'] : null,
            priority: is_string($input['priority'] ?? null) ? $input['priority'] : 'normal',
            constraints: is_array($input['constraints'] ?? null) ? $input['constraints'] : [],
        );
        if ($program->objective() === '') {
            throw new \InvalidArgumentException('objective is required.');
        }
        $program = $program->withSealedHashes();
        $this->store->save($program);
        $this->store->appendEvent(new PlanningEvent(
            PlanningEvent::makeId(),
            PlanningEvent::PROGRAM_CREATED,
            $program->programId(),
            $at,
            ['title' => $program->title(), 'objective' => $program->objective()],
        ));

        $autoPlan = ($input['autoPlan'] ?? true) === true;
        if ($autoPlan) {
            $hints = is_array($input['knowledgeHints'] ?? null) ? $input['knowledgeHints'] : [];
            $program = $this->planner->plan($program, $input, $hints);
        }

        return $program;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $knowledgeHints
     */
    public function plan(string $programId, array $input = [], array $knowledgeHints = []): Program
    {
        return $this->planner->plan($this->require($programId), $input, $knowledgeHints);
    }

    /**
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function start(string $programId, string $actorId = 'system', array $capacity = []): array
    {
        $program = $this->require($programId);
        if (!in_array($program->status(), [Program::STATUS_PLANNED, Program::STATUS_PAUSED, Program::STATUS_BLOCKED], true)) {
            if ($program->status() !== Program::STATUS_RUNNING && $program->status() !== Program::STATUS_SCHEDULING) {
                throw new \InvalidArgumentException('Program must be planned before start.');
            }
        }
        $at = Utc::now();
        if ($program->status() === Program::STATUS_PAUSED) {
            $program = $program->withPaused(false, $at);
            $this->store->appendEvent(new PlanningEvent(
                PlanningEvent::makeId(),
                PlanningEvent::PROGRAM_RESUMED,
                $program->programId(),
                $at,
            ));
        } else {
            $program = $program->withStatus(Program::STATUS_SCHEDULING, $at)->withSealedHashes();
        }
        $this->store->save($program);

        return $this->scheduler->tick($program, $actorId, $capacity);
    }

    /**
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function tick(string $programId, string $actorId = 'system', array $capacity = []): array
    {
        return $this->scheduler->tick($this->require($programId), $actorId, $capacity);
    }

    public function pause(string $programId): Program
    {
        $program = $this->require($programId)->withPaused(true, Utc::now())->withSealedHashes();
        $this->store->save($program);
        $this->store->appendEvent(new PlanningEvent(
            PlanningEvent::makeId(),
            PlanningEvent::PROGRAM_PAUSED,
            $program->programId(),
            Utc::now(),
        ));

        return $program;
    }

    public function resume(string $programId, string $actorId = 'system', array $capacity = []): array
    {
        $program = $this->require($programId)->withPaused(false, Utc::now())->withSealedHashes();
        $this->store->save($program);
        $this->store->appendEvent(new PlanningEvent(
            PlanningEvent::makeId(),
            PlanningEvent::PROGRAM_RESUMED,
            $program->programId(),
            Utc::now(),
        ));

        return $this->scheduler->tick($program, $actorId, $capacity);
    }

    public function cancel(string $programId): Program
    {
        $program = $this->require($programId)->withStatus(Program::STATUS_CANCELLED, Utc::now())->withSealedHashes();
        $this->store->save($program);

        return $program;
    }

    public function replan(string $programId, ?string $failedNodeId = null): Program
    {
        return $this->replanner->replan($this->require($programId), $failedNodeId);
    }

    /** @return array<string, mixed> */
    public function replanPreview(string $programId, ?string $failedNodeId = null): array
    {
        return $this->replanner->preview($this->require($programId), $failedNodeId);
    }

    public function completeNode(string $programId, string $nodeId, bool $succeeded, string $message = ''): Program
    {
        return $this->scheduler->completeNode($this->require($programId), $nodeId, $succeeded, $message);
    }

    public function get(string $programId): ?Program
    {
        return $this->store->find($programId);
    }

    /** @return list<Program> */
    public function list(?string $status = null, ?string $projectId = null): array
    {
        return $this->store->list($status, $projectId);
    }

    /** @return list<PlanningEvent> */
    public function events(string $programId, int $limit = 200): array
    {
        return $this->store->events($programId, $limit);
    }

    /** @return list<ProgramSnapshot> */
    public function snapshots(string $programId): array
    {
        return $this->store->snapshots($programId);
    }

    public function settings(): PlanningSettingsStore
    {
        return $this->settings;
    }

    public function deps(): DependencyManager
    {
        return $this->deps;
    }

    public function criticalPath(): CriticalPathAnalyzer
    {
        return $this->criticalPath;
    }

    private function require(string $programId): Program
    {
        $program = $this->store->find($programId);
        if ($program === null) {
            throw new \InvalidArgumentException('Unknown program: ' . $programId);
        }

        return $program;
    }
}
