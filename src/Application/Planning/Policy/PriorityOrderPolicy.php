<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class PriorityOrderPolicy implements SchedulingPolicy
{
    public function id(): string { return 'priority_order'; }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $weights = ['critical' => 40.0, 'high' => 25.0, 'normal' => 10.0, 'low' => 2.0];
        $p = $node->priority();
        if (!isset($weights[$p])) {
            $p = $program->priority();
        }
        $delta = $weights[$p] ?? 10.0;

        return ['admit' => true, 'scoreDelta' => $delta, 'reason' => 'priority=' . $p];
    }
}
