<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Port\PlanningSettingsStore;

final class PlanningQueryService
{
    public function __construct(
        private readonly PlanningPipelineService $pipeline,
        private readonly PlanningSettingsStore $settings,
        private readonly DependencyManager $deps,
        private readonly CriticalPathAnalyzer $criticalPath,
        private readonly ResourceAllocator $resources,
        private readonly WorkspaceAllocator $workspaces,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null, ?string $projectId = null): array
    {
        return array_map(static fn (Program $p) => self::summary($p), $this->pipeline->list($status, $projectId));
    }

    /** @return array<string, mixed>|null */
    public function get(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);
        if ($program === null) {
            return null;
        }
        $data = $program->toArray();
        $data['timeline'] = $this->timeline($programId);
        $data['snapshots'] = array_map(
            static fn ($s) => $s->toArray(),
            $this->pipeline->snapshots($programId)
        );

        return $data;
    }

    /** @return array<string, mixed>|null */
    public function graph(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);

        return $program?->graph()->toArray();
    }

    /** @return array<string, mixed>|null */
    public function dependencies(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);
        if ($program === null) {
            return null;
        }
        $items = [];
        foreach ($program->graph()->nodes() as $node) {
            $items[] = [
                'nodeId' => $node->nodeId(),
                'dependsOn' => $node->dependsOn(),
                'status' => $node->status(),
                'blockedReason' => $this->deps->blockedReason($program->graph(), $node),
            ];
        }

        return [
            'programId' => $programId,
            'waves' => $this->deps->topologicalWaves($program->graph()),
            'items' => $items,
            'validationErrors' => $this->deps->validate($program->graph()),
        ];
    }

    /** @return array<string, mixed>|null */
    public function criticalPathView(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);
        if ($program === null) {
            return null;
        }
        $cp = $this->criticalPath->analyze($program->graph());

        return ['programId' => $programId, 'criticalPath' => $cp];
    }

    /** @return array<string, mixed>|null */
    public function schedule(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);
        if ($program === null) {
            return null;
        }

        return [
            'programId' => $programId,
            'schedule' => $program->schedule(),
            'waves' => $this->deps->topologicalWaves($program->graph()),
            'status' => $program->status(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function allocations(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);
        if ($program === null) {
            return null;
        }
        $settings = $this->settings->get();
        $max = is_int($settings['maxInFlight'] ?? null) ? $settings['maxInFlight'] : 3;

        return [
            'programId' => $programId,
            'allocations' => $program->allocations(),
            'resources' => $this->resources->snapshot($program, $max),
            'workspaces' => $this->workspaces->snapshot($program, [
                'maxConcurrentWorkspaces' => $settings['maxConcurrentWorkspaces'] ?? 32,
            ]),
        ];
    }

    /** @return array<string, mixed>|null */
    public function queue(string $programId): ?array
    {
        $program = $this->pipeline->get($programId);
        if ($program === null) {
            return null;
        }
        $ready = [];
        $queued = [];
        $running = [];
        foreach ($program->graph()->nodes() as $node) {
            $row = [
                'nodeId' => $node->nodeId(),
                'title' => $node->title(),
                'status' => $node->status(),
                'priority' => $node->priority(),
                'missionId' => $node->missionId(),
            ];
            if (in_array($node->status(), ['pending', 'ready'], true) && $this->deps->depsSatisfied($program->graph(), $node)) {
                $ready[] = $row;
            } elseif ($node->status() === 'queued' || $node->status() === 'launching') {
                $queued[] = $row;
            } elseif ($node->status() === 'running') {
                $running[] = $row;
            }
        }

        return [
            'programId' => $programId,
            'ready' => $ready,
            'queued' => $queued,
            'running' => $running,
        ];
    }

    /**
     * Timeline generated from PlanningEvents.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $programId, int $limit = 200): array
    {
        return array_map(
            static fn (PlanningEvent $e) => [
                'eventId' => $e->eventId(),
                'type' => $e->type(),
                'programId' => $e->programId(),
                'nodeId' => $e->nodeId(),
                'atUtc' => $e->atUtc(),
                'payload' => $e->payload(),
                'message' => $e->type() . ($e->nodeId() ? ' ' . $e->nodeId() : ''),
            ],
            $this->pipeline->events($programId, $limit)
        );
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $all = $this->pipeline->list();
        $byStatus = [];
        foreach ($all as $p) {
            $byStatus[$p->status()] = ($byStatus[$p->status()] ?? 0) + 1;
        }
        $queueDepth = 0;
        $blocked = 0;
        foreach ($all as $p) {
            if ($p->status() === Program::STATUS_BLOCKED) {
                $blocked++;
            }
            foreach ($p->graph()->nodes() as $n) {
                if (in_array($n->status(), ['queued', 'ready', 'pending'], true)) {
                    $queueDepth++;
                }
            }
        }

        return [
            'programCount' => count($all),
            'byStatus' => $byStatus,
            'queueDepth' => $queueDepth,
            'blocked' => $blocked,
            'items' => array_map(static fn (Program $p) => self::summary($p), array_slice($all, 0, 20)),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $knowledgeHints
     * @return array<string, mixed>
     */
    public function create(array $input, array $knowledgeHints = []): array
    {
        if ($knowledgeHints !== []) {
            $input['knowledgeHints'] = $knowledgeHints;
        }

        return $this->pipeline->create($input)->toArray();
    }

    /** @param array<string, mixed> $capacity */
    public function start(string $programId, string $actorId, array $capacity = []): array
    {
        return $this->pipeline->start($programId, $actorId, $capacity);
    }

    /** @param array<string, mixed> $capacity */
    public function tick(string $programId, string $actorId, array $capacity = []): array
    {
        return $this->pipeline->tick($programId, $actorId, $capacity);
    }

    public function pause(string $programId): array
    {
        return $this->pipeline->pause($programId)->toArray();
    }

    /** @param array<string, mixed> $capacity */
    public function resume(string $programId, string $actorId, array $capacity = []): array
    {
        return $this->pipeline->resume($programId, $actorId, $capacity);
    }

    public function cancel(string $programId): array
    {
        return $this->pipeline->cancel($programId)->toArray();
    }

    public function replan(string $programId, ?string $failedNodeId = null): array
    {
        return $this->pipeline->replan($programId, $failedNodeId)->toArray();
    }

    public function replanPreview(string $programId, ?string $failedNodeId = null): array
    {
        return $this->pipeline->replanPreview($programId, $failedNodeId);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $knowledgeHints
     */
    public function plan(string $programId, array $input = [], array $knowledgeHints = []): array
    {
        return $this->pipeline->plan($programId, $input, $knowledgeHints)->toArray();
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings->get();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array
    {
        return $this->settings->put($settings);
    }

    public function pipeline(): PlanningPipelineService
    {
        return $this->pipeline;
    }

    /** @return array<string, mixed> */
    private static function summary(Program $p): array
    {
        $data = $p->toArray();

        return [
            'programId' => $data['programId'],
            'title' => $data['title'],
            'objective' => $data['objective'],
            'status' => $data['status'],
            'priority' => $data['priority'],
            'projectId' => $data['projectId'],
            'nodeCount' => count($data['graph']['nodes'] ?? []),
            'estimates' => $data['estimates'],
            'reproducibilityFingerprint' => $data['reproducibilityFingerprint'],
            'integrityHash' => $data['integrityHash'],
            'schemaVersion' => $data['schemaVersion'],
            'updatedAtUtc' => $data['updatedAtUtc'],
        ];
    }
}
