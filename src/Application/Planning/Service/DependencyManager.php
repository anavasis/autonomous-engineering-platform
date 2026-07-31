<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\ProgramNode;

final class DependencyManager
{
    /**
     * @return list<string>
     */
    public function validate(MissionGraph $graph): array
    {
        $errors = [];
        $ids = [];
        foreach ($graph->nodes() as $node) {
            if (isset($ids[$node->nodeId()])) {
                $errors[] = 'duplicate node ' . $node->nodeId();
            }
            $ids[$node->nodeId()] = true;
            foreach ($node->dependsOn() as $dep) {
                if (!isset($ids[$dep]) && $graph->find($dep) === null) {
                    // may appear later in list; check after
                }
            }
        }
        foreach ($graph->nodes() as $node) {
            foreach ($node->dependsOn() as $dep) {
                if ($graph->find($dep) === null) {
                    $errors[] = 'missing dependency ' . $dep . ' for ' . $node->nodeId();
                }
                if ($dep === $node->nodeId()) {
                    $errors[] = 'self dependency on ' . $node->nodeId();
                }
            }
        }
        foreach ($graph->edges() as $edge) {
            if ($graph->find($edge['from']) === null || $graph->find($edge['to']) === null) {
                $errors[] = 'edge references unknown node';
            }
        }
        if ($this->hasCycle($graph)) {
            $errors[] = 'graph contains a cycle';
        }

        return $errors;
    }

    /** @return list<ProgramNode> */
    public function readySet(MissionGraph $graph): array
    {
        $ready = [];
        foreach ($graph->nodes() as $node) {
            if (!in_array($node->status(), [
                ProgramNode::STATUS_PENDING,
                ProgramNode::STATUS_READY,
                ProgramNode::STATUS_QUEUED,
            ], true)) {
                continue;
            }
            if ($this->depsSatisfied($graph, $node)) {
                $ready[] = $node;
            }
        }

        return $ready;
    }

    public function depsSatisfied(MissionGraph $graph, ProgramNode $node): bool
    {
        foreach ($node->dependsOn() as $depId) {
            $dep = $graph->find($depId);
            if ($dep === null) {
                return false;
            }
            $edgeType = 'succeeds_before';
            foreach ($graph->edges() as $edge) {
                if ($edge['from'] === $depId && $edge['to'] === $node->nodeId()) {
                    $edgeType = $edge['type'];
                    break;
                }
            }
            if ($edgeType === 'finishes_before') {
                if (!in_array($dep->status(), [ProgramNode::STATUS_SUCCEEDED, ProgramNode::STATUS_SKIPPED], true)) {
                    return false;
                }
            } else {
                if ($dep->status() !== ProgramNode::STATUS_SUCCEEDED) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<list<string>> */
    public function topologicalWaves(MissionGraph $graph): array
    {
        $remaining = [];
        foreach ($graph->nodes() as $node) {
            $remaining[$node->nodeId()] = $node->dependsOn();
        }
        $waves = [];
        while ($remaining !== []) {
            $wave = [];
            foreach ($remaining as $id => $deps) {
                $unmet = array_filter($deps, static fn (string $d): bool => isset($remaining[$d]));
                if ($unmet === []) {
                    $wave[] = $id;
                }
            }
            if ($wave === []) {
                break; // cycle
            }
            $waves[] = $wave;
            foreach ($wave as $id) {
                unset($remaining[$id]);
            }
        }

        return $waves;
    }

    public function blockedReason(MissionGraph $graph, ProgramNode $node): string
    {
        foreach ($node->dependsOn() as $depId) {
            $dep = $graph->find($depId);
            if ($dep === null) {
                return 'missing ' . $depId;
            }
            if ($dep->status() === ProgramNode::STATUS_FAILED) {
                return 'failed dependency ' . $depId;
            }
            if ($dep->status() !== ProgramNode::STATUS_SUCCEEDED && $dep->status() !== ProgramNode::STATUS_SKIPPED) {
                return 'waiting on ' . $depId . ' (' . $dep->status() . ')';
            }
        }

        return '';
    }

    private function hasCycle(MissionGraph $graph): bool
    {
        $waves = $this->topologicalWaves($graph);
        $count = 0;
        foreach ($waves as $wave) {
            $count += count($wave);
        }

        return $count !== count($graph->nodes());
    }
}
