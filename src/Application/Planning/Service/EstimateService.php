<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\ProgramNode;

final class EstimateService
{
    /**
     * @param list<array<string, mixed>> $knowledgeHints
     */
    public function estimateNode(ProgramNode $node, array $knowledgeHints = []): ProgramNode
    {
        $base = 300;
        $cost = 1.0;
        $confidence = 0.5;
        if ($knowledgeHints !== []) {
            $base = 240;
            $confidence = 0.7;
        }
        $priorityBoost = match ($node->priority()) {
            'critical' => 1.2,
            'high' => 1.1,
            'low' => 0.9,
            default => 1.0,
        };

        return $node->withEstimates([
            'durationSeconds' => (int) round($base * $priorityBoost),
            'costUnits' => $cost * $priorityBoost,
            'confidence' => $confidence,
        ]);
    }

    /**
     * @return array{durationSeconds: int, costUnits: float, confidence: float}
     */
    public function estimateProgram(MissionGraph $graph): array
    {
        $waves = (new DependencyManager())->topologicalWaves($graph);
        $duration = 0;
        $cost = 0.0;
        $conf = 0.0;
        $n = 0;
        foreach ($waves as $wave) {
            $waveDur = 0;
            foreach ($wave as $id) {
                $node = $graph->find($id);
                if ($node === null) {
                    continue;
                }
                $d = is_numeric($node->estimates()['durationSeconds'] ?? null) ? (int) $node->estimates()['durationSeconds'] : 300;
                $c = is_numeric($node->estimates()['costUnits'] ?? null) ? (float) $node->estimates()['costUnits'] : 1.0;
                $cf = is_numeric($node->estimates()['confidence'] ?? null) ? (float) $node->estimates()['confidence'] : 0.5;
                $waveDur = max($waveDur, $d);
                $cost += $c;
                $conf += $cf;
                $n++;
            }
            $duration += $waveDur;
        }

        return [
            'durationSeconds' => $duration,
            'costUnits' => $cost,
            'confidence' => $n > 0 ? $conf / $n : 0.5,
        ];
    }
}
