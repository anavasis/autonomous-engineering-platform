<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\ProgramStore;

final class Replanner
{
    public function __construct(
        private readonly ProgramStore $store,
        private readonly ProgramPlanner $planner,
        private readonly DependencyManager $deps,
        private readonly EstimateService $estimates,
        private readonly CriticalPathAnalyzer $criticalPath,
    ) {
    }

    /**
     * @return array{current: array<string, mixed>, proposed: array<string, mixed>, diff: array<string, mixed>}
     */
    public function preview(Program $program, ?string $failedNodeId = null): array
    {
        $proposed = $this->buildRevisedGraph($program, $failedNodeId);

        return [
            'current' => $program->graph()->toArray(),
            'proposed' => $proposed->toArray(),
            'diff' => [
                'generation' => $proposed->generation(),
                'superseded' => $failedNodeId,
                'addedNodes' => array_values(array_filter(
                    array_map(static fn (ProgramNode $n) => $n->nodeId(), $proposed->nodes()),
                    static fn (string $id): bool => $program->graph()->find($id) === null
                )),
            ],
        ];
    }

    public function replan(Program $program, ?string $failedNodeId = null): Program
    {
        $at = Utc::now();
        $program = $program->withStatus(Program::STATUS_REPLANNING, $at);
        $revised = $this->buildRevisedGraph($program, $failedNodeId);
        $errors = $this->deps->validate($revised);
        if ($errors !== []) {
            throw new \InvalidArgumentException('Replan produced invalid graph: ' . implode('; ', $errors));
        }
        $cp = $this->criticalPath->analyze($revised);
        $est = $this->estimates->estimateProgram($revised);
        $program = $program
            ->withGraph($revised, $at)
            ->withCriticalPath($cp, $at)
            ->withEstimates($est, $at)
            ->withStatus(Program::STATUS_RUNNING, $at)
            ->withSealedHashes();
        $this->store->save($program);
        $this->store->appendEvent(new PlanningEvent(
            PlanningEvent::makeId(),
            PlanningEvent::PROGRAM_REPLANNED,
            $program->programId(),
            $at,
            [
                'failedNodeId' => $failedNodeId,
                'generation' => $revised->generation(),
                'criticalPath' => $cp['nodeIds'],
            ],
        ));

        return $program;
    }

    private function buildRevisedGraph(Program $program, ?string $failedNodeId): MissionGraph
    {
        $nodes = [];
        $edges = [];
        foreach ($program->graph()->nodes() as $node) {
            if ($failedNodeId !== null && $node->nodeId() === $failedNodeId) {
                $nodes[] = $node->withStatus(ProgramNode::STATUS_SUPERSEDED);
                $replacement = $this->estimates->estimateNode(ProgramNode::fromArray([
                    'nodeId' => ProgramNode::makeId(),
                    'title' => 'Replanned: ' . $node->title(),
                    'objective' => $node->objective(),
                    'priority' => $node->priority(),
                    'dependsOn' => $node->dependsOn(),
                    'failurePolicy' => $node->failurePolicy(),
                    'constraints' => $node->constraints(),
                    'status' => ProgramNode::STATUS_PENDING,
                ]));
                $nodes[] = $replacement;
                foreach ($replacement->dependsOn() as $dep) {
                    $edges[] = ['from' => $dep, 'to' => $replacement->nodeId(), 'type' => 'succeeds_before'];
                }
                // redirect dependents of failed node to replacement
                foreach ($program->graph()->nodes() as $other) {
                    if (in_array($failedNodeId, $other->dependsOn(), true) && $other->nodeId() !== $failedNodeId) {
                        $deps = array_map(
                            static fn (string $d): string => $d === $failedNodeId ? $replacement->nodeId() : $d,
                            $other->dependsOn()
                        );
                        $nodes[] = ProgramNode::fromArray(array_merge($other->toArray(), ['dependsOn' => $deps]));
                    }
                }
                continue;
            }
            // skip if already added as redirected dependent
            $already = false;
            foreach ($nodes as $existing) {
                if ($existing->nodeId() === $node->nodeId()) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $nodes[] = $node;
            }
        }

        // dedupe by nodeId keeping last
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n->nodeId()] = $n;
        }
        $nodes = array_values($byId);
        if ($edges === []) {
            foreach ($nodes as $n) {
                foreach ($n->dependsOn() as $dep) {
                    $edges[] = ['from' => $dep, 'to' => $n->nodeId(), 'type' => 'succeeds_before'];
                }
            }
        }

        return new MissionGraph($nodes, $edges, $program->graph()->generation() + 1);
    }
}
