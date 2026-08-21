<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\ProgramStore;

/**
 * Decomposes a program objective into a mission DAG (knowledge-assisted when hints provided).
 */
final class ProgramPlanner
{
    public function __construct(
        private readonly DependencyManager $deps,
        private readonly EstimateService $estimates,
        private readonly CriticalPathAnalyzer $criticalPath,
        private readonly ProgramStore $store,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $knowledgeHints
     */
    public function plan(Program $program, array $input = [], array $knowledgeHints = []): Program
    {
        $at = Utc::now();
        $program = $program->withStatus(Program::STATUS_PLANNING, $at);

        $objectives = [];
        if (isset($input['nodes']) && is_array($input['nodes']) && $input['nodes'] !== []) {
            foreach ($input['nodes'] as $n) {
                if (is_array($n)) {
                    $objectives[] = $n;
                }
            }
        } else {
            $objectives = $this->decompose($program->objective(), $knowledgeHints);
        }

        $nodes = [];
        $prevId = null;
        $edges = [];
        foreach ($objectives as $i => $spec) {
            $node = ProgramNode::fromArray([
                'nodeId' => is_string($spec['nodeId'] ?? null) ? $spec['nodeId'] : ProgramNode::makeId(),
                'title' => is_string($spec['title'] ?? null) ? $spec['title'] : ('Step ' . ($i + 1)),
                'objective' => is_string($spec['objective'] ?? null) ? $spec['objective'] : $program->objective(),
                'priority' => is_string($spec['priority'] ?? null) ? $spec['priority'] : $program->priority(),
                'dependsOn' => is_array($spec['dependsOn'] ?? null) ? $spec['dependsOn'] : ($prevId !== null ? [$prevId] : []),
                'failurePolicy' => is_string($spec['failurePolicy'] ?? null) ? $spec['failurePolicy'] : 'retry',
                'constraints' => is_array($spec['constraints'] ?? null) ? $spec['constraints'] : $program->constraints(),
            ]);
            $node = $this->estimates->estimateNode($node, $knowledgeHints);
            if ($prevId !== null && $node->dependsOn() === [$prevId]) {
                $edges[] = ['from' => $prevId, 'to' => $node->nodeId(), 'type' => 'succeeds_before'];
            } else {
                foreach ($node->dependsOn() as $dep) {
                    $edges[] = ['from' => $dep, 'to' => $node->nodeId(), 'type' => 'succeeds_before'];
                }
            }
            $nodes[] = $node;
            $prevId = $node->nodeId();
        }

        $graph = new MissionGraph($nodes, $edges, $program->graph()->generation());
        $errors = $this->deps->validate($graph);
        if ($errors !== []) {
            throw new \InvalidArgumentException('Invalid mission graph: ' . implode('; ', $errors));
        }

        $cp = $this->criticalPath->analyze($graph);
        $est = $this->estimates->estimateProgram($graph);
        $refs = [];
        foreach ($knowledgeHints as $hint) {
            if (is_string($hint['knowledgeId'] ?? null)) {
                $refs[] = 'knowledge:' . $hint['knowledgeId'];
            }
        }

        $program = $program
            ->withGraph($graph, $at)
            ->withCriticalPath($cp, $at)
            ->withEstimates($est, $at)
            ->withKnowledgeRefs($refs, $at)
            ->withStatus(Program::STATUS_PLANNED, $at)
            ->withSealedHashes();

        $this->store->save($program);
        $this->store->appendEvent(new PlanningEvent(
            PlanningEvent::makeId(),
            PlanningEvent::PROGRAM_PLANNED,
            $program->programId(),
            $at,
            [
                'nodeCount' => count($nodes),
                'criticalPath' => $cp['nodeIds'],
                'estimates' => $est,
                'knowledgeRefs' => $refs,
            ],
        ));

        return $program;
    }

    /**
     * @param list<array<string, mixed>> $knowledgeHints
     * @return list<array<string, mixed>>
     */
    private function decompose(string $objective, array $knowledgeHints): array
    {
        $parts = preg_split('/\s*(?:;|\n| then | and then )\s*/i', $objective) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        if (count($parts) <= 1) {
            // Default 3-phase engineering program
            return [
                ['title' => 'Inspect & scope', 'objective' => 'Inspect and define scope for: ' . $objective, 'priority' => 'high'],
                ['title' => 'Implement changes', 'objective' => 'Implement: ' . $objective, 'priority' => 'normal'],
                ['title' => 'Validate & seal', 'objective' => 'Validate, review, and seal work for: ' . $objective, 'priority' => 'high'],
            ];
        }
        $out = [];
        foreach ($parts as $i => $part) {
            $out[] = [
                'title' => 'Phase ' . ($i + 1),
                'objective' => $part,
                'priority' => $i === 0 ? 'high' : 'normal',
            ];
        }
        if ($knowledgeHints !== []) {
            $out[0]['title'] = 'Knowledge-informed: ' . $out[0]['title'];
        }

        return $out;
    }
}
