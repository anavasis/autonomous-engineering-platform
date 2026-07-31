<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class DeadlineUrgencyPolicy implements SchedulingPolicy
{
    public function id(): string { return 'deadline_urgency'; }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $onCritical = is_array($context['criticalNodeIds'] ?? null) && in_array($node->nodeId(), $context['criticalNodeIds'], true);
        $delta = $onCritical ? 15.0 : 0.0;
        $deadline = $program->constraints()['deadlineUtc'] ?? null;
        if (is_string($deadline) && $deadline !== '') {
            $left = strtotime($deadline . ' UTC') - time();
            if ($left < 86400) {
                $delta += 10.0;
            }
        }

        return ['admit' => true, 'scoreDelta' => $delta, 'reason' => $onCritical ? 'on critical path' : 'not critical'];
    }
}
