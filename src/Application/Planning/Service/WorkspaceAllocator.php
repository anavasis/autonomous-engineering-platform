<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Service;

use Aep\Application\Planning\Model\Program;

final class WorkspaceAllocator
{
    /**
     * @param array<string, mixed> $workspaceSettings
     * @return array{workspacesInUse: int, maxConcurrentWorkspaces: int}
     */
    public function snapshot(Program $program, array $workspaceSettings, int $globalInUse = 0): array
    {
        $max = is_int($workspaceSettings['maxConcurrentWorkspaces'] ?? null)
            ? $workspaceSettings['maxConcurrentWorkspaces']
            : 32;
        $used = $globalInUse;
        foreach ($program->graph()->nodes() as $node) {
            if (in_array($node->status(), ['launching', 'running'], true)) {
                $used++;
            }
        }

        return [
            'workspacesInUse' => $used,
            'maxConcurrentWorkspaces' => $max,
        ];
    }
}
