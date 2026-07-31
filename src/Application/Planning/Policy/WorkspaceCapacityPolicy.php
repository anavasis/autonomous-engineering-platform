<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class WorkspaceCapacityPolicy implements SchedulingPolicy
{
    public function __construct(private readonly int $maxConcurrent = 32) {}

    public function id(): string { return 'workspace_capacity'; }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $used = is_int($context['workspacesInUse'] ?? null) ? $context['workspacesInUse'] : 0;
        $max = is_int($context['maxConcurrentWorkspaces'] ?? null) ? $context['maxConcurrentWorkspaces'] : $this->maxConcurrent;
        $ok = $used < $max;

        return [
            'admit' => $ok,
            'scoreDelta' => $ok ? 0.5 : 0.0,
            'reason' => $ok ? 'workspace capacity ok' : 'workspace capacity exhausted',
        ];
    }
}
