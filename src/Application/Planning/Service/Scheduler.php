<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Model\ProgramSnapshot;
use Aep\Application\Planning\Policy\SchedulingPolicySet;
use Aep\Application\Planning\Port\PlanningLaunchPort;
use Aep\Application\Planning\Port\PlanningSettingsStore;
use Aep\Application\Planning\Port\ProgramStore;

/**
 * Scheduler evaluates configured SchedulingPolicy rules — no hardcoded admit/rank logic.
 */
final class Scheduler
{
    public function __construct(
        private readonly ProgramStore $store,
        private readonly PlanningSettingsStore $settings,
        private readonly SchedulingPolicySet $policies,
        private readonly DependencyManager $deps,
        private readonly ResourceAllocator $resources,
        private readonly ProviderAllocator $providers,
        private readonly WorkspaceAllocator $workspaces,
        private readonly FailureRecoveryPolicy $recovery,
        private readonly PlanningLaunchPort $launcher,
        private readonly CriticalPathAnalyzer $criticalPath,
        private readonly ?Replanner $replanner = null,
    ) {
    }

    /**
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function tick(Program $program, string $actorId = 'system', array $capacity = []): array
    {
        if ($program->isPaused() || in_array($program->status(), [
            Program::STATUS_COMPLETED,
            Program::STATUS_FAILED,
            Program::STATUS_CANCELLED,
            Program::STATUS_DRAFT,
        ], true)) {
            return ['admitted' => [], 'reason' => 'program not schedulable', 'program' => $program->toArray()];
        }

        $at = Utc::now();
        $settings = $this->settings->get();
        $maxInFlight = is_int($settings['maxInFlight'] ?? null) ? $settings['maxInFlight'] : 3;

        $resourceSnap = $this->resources->snapshot($program, $maxInFlight);
        $wsSnap = $this->workspaces->snapshot(
            $program,
            is_array($capacity['workspaceSettings'] ?? null) ? $capacity['workspaceSettings'] : [],
            is_int($capacity['workspacesInUse'] ?? null) ? $capacity['workspacesInUse'] : 0,
        );

        $ready = $this->deps->readySet($program->graph());
        $readyIds = array_map(static fn (ProgramNode $n) => $n->nodeId(), $ready);
        $cp = $this->criticalPath->analyze($program->graph());

        $context = [
            'readyIds' => $readyIds,
            'inFlight' => $resourceSnap['inFlight'],
            'enabledProviderIds' => is_array($capacity['enabledProviderIds'] ?? null) ? $capacity['enabledProviderIds'] : [],
            'defaultProviderId' => is_string($capacity['defaultProviderId'] ?? null) ? $capacity['defaultProviderId'] : null,
            'workspacesInUse' => $wsSnap['workspacesInUse'],
            'maxConcurrentWorkspaces' => $wsSnap['maxConcurrentWorkspaces'],
            'criticalNodeIds' => $cp['nodeIds'],
            'spentCostUnits' => is_numeric($capacity['spentCostUnits'] ?? null) ? (float) $capacity['spentCostUnits'] : 0.0,
            'settings' => $settings,
        ];

        $scored = [];
        $policyTrace = [];
        foreach ($ready as $node) {
            $admit = true;
            $score = 0.0;
            $trace = [];
            foreach ($this->policies->all() as $policy) {
                $result = $policy->evaluate($program, $node, $context);
                $passed = ($result['admit'] ?? true) === true;
                $delta = is_numeric($result['scoreDelta'] ?? null) ? (float) $result['scoreDelta'] : 0.0;
                $trace[] = [
                    'policy' => $policy->id(),
                    'admit' => $passed,
                    'scoreDelta' => $delta,
                    'reason' => is_string($result['reason'] ?? null) ? $result['reason'] : '',
                ];
                if (!$passed) {
                    $admit = false;
                }
                $score += $delta;
            }
            $policyTrace[$node->nodeId()] = $trace;
            if ($admit) {
                $scored[] = ['node' => $node, 'score' => $score];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $admitted = [];
        $graph = $program->graph();
        $slots = max(0, $maxInFlight - $resourceSnap['inFlight']);

        foreach ($scored as $row) {
            if ($slots <= 0) {
                break;
            }
            /** @var ProgramNode $node */
            $node = $row['node'];
            $execSettings = is_array($capacity['executionSettings'] ?? null) ? $capacity['executionSettings'] : [];
            $node = $this->providers->allocate($node, $execSettings);
            $node = $node->withStatus(ProgramNode::STATUS_QUEUED);
            $graph = $graph->replaceNode($node);
            $this->store->appendEvent(new PlanningEvent(
                PlanningEvent::makeId(),
                PlanningEvent::NODE_QUEUED,
                $program->programId(),
                $at,
                ['score' => $row['score'], 'providerId' => $node->providerId()],
                $node->nodeId(),
            ));

            $node = $node->withStatus(ProgramNode::STATUS_LAUNCHING);
            $graph = $graph->replaceNode($node);

            try {
                $launched = $this->launcher->launchNode(
                    $program->withGraph($graph, $at),
                    $node,
                    $actorId,
                );
                $node = $node->withLaunch($launched['missionId'], $launched['runId'], $node->providerId());
                $graph = $graph->replaceNode($node);
                $this->store->indexMission($launched['missionId'], $program->programId());
                $this->store->appendEvent(new PlanningEvent(
                    PlanningEvent::makeId(),
                    PlanningEvent::NODE_STARTED,
                    $program->programId(),
                    Utc::now(),
                    [
                        'missionId' => $launched['missionId'],
                        'runId' => $launched['runId'],
                        'message' => $launched['message'],
                    ],
                    $node->nodeId(),
                ));
                $admitted[] = $node->nodeId();
                $slots--;
                $context['inFlight']++;
                $context['workspacesInUse']++;
            } catch (\Throwable $e) {
                $node = $node->withStatus(ProgramNode::STATUS_FAILED);
                $graph = $graph->replaceNode($node);
                $this->store->appendEvent(new PlanningEvent(
                    PlanningEvent::makeId(),
                    PlanningEvent::NODE_FAILED,
                    $program->programId(),
                    Utc::now(),
                    ['error' => $e->getMessage()],
                    $node->nodeId(),
                ));
                $program = $this->handleFailure($program->withGraph($graph, Utc::now()), $node, $actorId);
                $graph = $program->graph();
            }
        }

        $waves = $this->deps->topologicalWaves($graph);
        $schedule = [
            'waves' => $waves,
            'readyIds' => $readyIds,
            'queued' => $admitted,
            'inFlight' => $context['inFlight'],
            'policyTrace' => $policyTrace,
            'nextTickAtUtc' => $at,
        ];
        $allocations = [
            'resources' => $resourceSnap,
            'workspaces' => $wsSnap,
            'providers' => [
                'defaultProviderId' => $context['defaultProviderId'],
                'enabledProviderIds' => $context['enabledProviderIds'],
            ],
        ];

        $status = Program::STATUS_RUNNING;
        if ($admitted === [] && $readyIds === [] && $this->allTerminal($graph)) {
            $status = $this->anyFailed($graph) ? Program::STATUS_FAILED : Program::STATUS_COMPLETED;
        } elseif ($admitted === [] && $readyIds === [] && !$this->allTerminal($graph)) {
            $status = Program::STATUS_BLOCKED;
        } elseif ($admitted === [] && $readyIds !== []) {
            $status = Program::STATUS_BLOCKED;
        }

        $program = $program
            ->withGraph($graph, $at)
            ->withSchedule($schedule, $at)
            ->withAllocations($allocations, $at)
            ->withCriticalPath($cp, $at)
            ->withStatus($status === Program::STATUS_RUNNING && $program->status() === Program::STATUS_PLANNED
                ? Program::STATUS_SCHEDULING
                : $status, $at)
            ->withSealedHashes();

        if ($program->status() === Program::STATUS_SCHEDULING && $admitted !== []) {
            $program = $program->withStatus(Program::STATUS_RUNNING, $at);
        }

        $this->store->save($program);
        $this->maybeSnapshot($program);

        if ($program->status() === Program::STATUS_COMPLETED) {
            $this->store->appendEvent(new PlanningEvent(
                PlanningEvent::makeId(),
                PlanningEvent::PROGRAM_COMPLETED,
                $program->programId(),
                Utc::now(),
                ['nodeCount' => count($graph->nodes())],
            ));
        }

        return [
            'admitted' => $admitted,
            'readyIds' => $readyIds,
            'status' => $program->status(),
            'policyTrace' => $policyTrace,
            'program' => $program->toArray(),
        ];
    }

    public function completeNode(Program $program, string $nodeId, bool $succeeded, string $message = ''): Program
    {
        $node = $program->graph()->find($nodeId);
        if ($node === null) {
            return $program;
        }
        $at = Utc::now();
        if ($succeeded) {
            $node = $node->withStatus(ProgramNode::STATUS_SUCCEEDED);
            $graph = $program->graph()->replaceNode($node);
            $this->store->appendEvent(new PlanningEvent(
                PlanningEvent::makeId(),
                PlanningEvent::NODE_COMPLETED,
                $program->programId(),
                $at,
                ['message' => $message],
                $nodeId,
            ));
            $program = $program->withGraph($graph, $at)->withSealedHashes();
            $this->store->save($program);
            $this->maybeSnapshot($program);

            return $program;
        }

        $node = $node->withStatus(ProgramNode::STATUS_FAILED);
        $graph = $program->graph()->replaceNode($node);
        $this->store->appendEvent(new PlanningEvent(
            PlanningEvent::makeId(),
            PlanningEvent::NODE_FAILED,
            $program->programId(),
            $at,
            ['message' => $message],
            $nodeId,
        ));

        return $this->handleFailure($program->withGraph($graph, $at), $node, 'system');
    }

    private function handleFailure(Program $program, ProgramNode $node, string $actorId): Program
    {
        $decision = $this->recovery->decide($node);
        $at = Utc::now();
        if ($decision['action'] === 'retry') {
            $attempt = (int) ($node->retry()['attempt'] ?? 0) + 1;
            $reset = $node->withRetryAttempt($attempt)->withStatus(ProgramNode::STATUS_PENDING);
            // clear mission binding for relaunch
            $reset = ProgramNode::fromArray(array_merge($reset->toArray(), [
                'missionId' => null,
                'runId' => null,
                'status' => ProgramNode::STATUS_PENDING,
            ]));
            $graph = $program->graph()->replaceNode($reset);
            $program = $program->withGraph($graph, $at)->withStatus(Program::STATUS_RUNNING, $at)->withSealedHashes();
            $this->store->save($program);

            return $program;
        }
        if ($decision['action'] === 'skip') {
            $skipped = $node->withStatus(ProgramNode::STATUS_SKIPPED);
            $graph = $program->graph()->replaceNode($skipped);
            $program = $program->withGraph($graph, $at)->withSealedHashes();
            $this->store->save($program);

            return $program;
        }
        if ($decision['action'] === 'replan' && $this->replanner !== null) {
            return $this->replanner->replan($program, $node->nodeId());
        }

        return $program->withStatus(Program::STATUS_FAILED, $at)->withSealedHashes();
    }

    private function maybeSnapshot(Program $program): void
    {
        $settings = $this->settings->get();
        $every = is_int($settings['snapshotEveryEvents'] ?? null) ? $settings['snapshotEveryEvents'] : 5;
        $events = $this->store->events($program->programId(), $every + 1);
        if (count($events) > 0 && count($events) % max(1, $every) === 0) {
            $program = $program->bumpSnapshotSequence();
            $last = $events[0] ?? null;
            $snap = (new ProgramSnapshot(
                ProgramSnapshot::makeId(),
                $program->programId(),
                $program->snapshotSequence(),
                Utc::now(),
                $program->toArray(),
                $program->schedule(),
                $program->allocations(),
                $last?->eventId() ?? '',
            ))->withIntegrity();
            $this->store->saveSnapshot($snap);
            $this->store->save($program);
        }
    }

    private function allTerminal(object $graph): bool
    {
        foreach ($graph->nodes() as $node) {
            if (!in_array($node->status(), [
                ProgramNode::STATUS_SUCCEEDED,
                ProgramNode::STATUS_FAILED,
                ProgramNode::STATUS_SKIPPED,
                ProgramNode::STATUS_CANCELLED,
                ProgramNode::STATUS_SUPERSEDED,
            ], true)) {
                return false;
            }
        }

        return $graph->nodes() !== [];
    }

    private function anyFailed(object $graph): bool
    {
        foreach ($graph->nodes() as $node) {
            if ($node->status() === ProgramNode::STATUS_FAILED) {
                return true;
            }
        }

        return false;
    }
}
