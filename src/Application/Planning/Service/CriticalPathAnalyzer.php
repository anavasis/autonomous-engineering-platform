<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\MissionGraph;

final class CriticalPathAnalyzer
{
    /**
     * @return array{nodeIds: list<string>, durationSeconds: int, slack: array<string, int>}
     */
    public function analyze(MissionGraph $graph): array
    {
        $duration = [];
        $preds = [];
        foreach ($graph->nodes() as $node) {
            $duration[$node->nodeId()] = is_numeric($node->estimates()['durationSeconds'] ?? null)
                ? (int) $node->estimates()['durationSeconds']
                : 300;
            $preds[$node->nodeId()] = $node->dependsOn();
        }

        $earliest = [];
        $order = [];
        $remaining = $preds;
        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $id => $deps) {
                $ready = true;
                $start = 0;
                foreach ($deps as $d) {
                    if (!isset($earliest[$d])) {
                        $ready = false;
                        break;
                    }
                    $start = max($start, $earliest[$d] + ($duration[$d] ?? 0));
                }
                if ($ready) {
                    $earliest[$id] = $start;
                    $order[] = $id;
                    unset($remaining[$id]);
                    $progress = true;
                }
            }
            if (!$progress) {
                break;
            }
        }

        $total = 0;
        foreach ($earliest as $id => $start) {
            $total = max($total, $start + ($duration[$id] ?? 0));
        }

        $latest = [];
        foreach (array_reverse($order) as $id) {
            $succs = [];
            foreach ($preds as $nid => $deps) {
                if (in_array($id, $deps, true)) {
                    $succs[] = $nid;
                }
            }
            if ($succs === []) {
                $latest[$id] = $total - ($duration[$id] ?? 0);
            } else {
                $min = PHP_INT_MAX;
                foreach ($succs as $s) {
                    $min = min($min, ($latest[$s] ?? $total) - 0);
                }
                // latest start = min(latest start of succ) - duration(id) ... use earliest finish of self relative
                $lf = PHP_INT_MAX;
                foreach ($succs as $s) {
                    $lf = min($lf, $latest[$s] ?? $total);
                }
                $latest[$id] = $lf - ($duration[$id] ?? 0);
            }
        }

        $slack = [];
        $critical = [];
        foreach ($order as $id) {
            $s = ($latest[$id] ?? $earliest[$id] ?? 0) - ($earliest[$id] ?? 0);
            $slack[$id] = max(0, $s);
            if ($slack[$id] === 0) {
                $critical[] = $id;
            }
        }

        return [
            'nodeIds' => $critical,
            'durationSeconds' => $total,
            'slack' => $slack,
        ];
    }
}
