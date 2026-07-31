<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class CostBudgetPolicy implements SchedulingPolicy
{
    public function id(): string { return 'cost_budget'; }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $budget = $program->constraints()['maxCostUnits'] ?? null;
        if (!is_numeric($budget)) {
            return ['admit' => true, 'scoreDelta' => 0.0, 'reason' => 'no budget'];
        }
        $spent = is_numeric($context['spentCostUnits'] ?? null) ? (float) $context['spentCostUnits'] : 0.0;
        $nodeCost = is_numeric($node->estimates()['costUnits'] ?? null) ? (float) $node->estimates()['costUnits'] : 1.0;
        $ok = ($spent + $nodeCost) <= (float) $budget;

        return [
            'admit' => $ok,
            'scoreDelta' => $ok ? 0.2 : 0.0,
            'reason' => $ok ? 'within budget' : 'budget exceeded',
        ];
    }
}
