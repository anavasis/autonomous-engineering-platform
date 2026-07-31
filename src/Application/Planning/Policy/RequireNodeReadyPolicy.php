<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class RequireNodeReadyPolicy implements SchedulingPolicy
{
    public function id(): string
    {
        return 'require_node_ready';
    }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $readyIds = is_array($context['readyIds'] ?? null) ? $context['readyIds'] : [];
        $ok = in_array($node->nodeId(), $readyIds, true);

        return [
            'admit' => $ok,
            'scoreDelta' => 0.0,
            'reason' => $ok ? 'ready' : 'not ready',
        ];
    }
}
