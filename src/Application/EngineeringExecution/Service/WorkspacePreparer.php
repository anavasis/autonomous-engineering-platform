<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\EngineeringWorkspace\Service\EngineeringWorkspaceService;

/**
 * Thin adapter: delegates provisioning to EngineeringWorkspaceService.
 * Preserves the prepare(...) return contract (workspace filesystem path).
 */
final class WorkspacePreparer
{
    public function __construct(
        private readonly EngineeringWorkspaceService $workspaces,
        private readonly ?string $legacyExecutionRoot = null,
    ) {
    }

    /**
     * @param array<string, string> $contextFiles
     * @param array<string, mixed> $options missionId, runId, projectId, allowedPaths, artifactMounts, git, redactions
     */
    public function prepare(
        string $sessionId,
        PromptBundle $prompt,
        array $contextFiles,
        array $options = [],
    ): string {
        $missionId = is_string($options['missionId'] ?? null) ? (string) $options['missionId'] : 'mission_unknown';
        $runId = is_string($options['runId'] ?? null) ? (string) $options['runId'] : 'run_unknown';
        $projectId = is_string($options['projectId'] ?? null) ? (string) $options['projectId'] : null;
        $allowedPaths = [];
        if (isset($options['allowedPaths']) && is_array($options['allowedPaths'])) {
            foreach ($options['allowedPaths'] as $p) {
                if (is_string($p) && $p !== '') {
                    $allowedPaths[] = $p;
                }
            }
        }
        $artifactMounts = [];
        if (isset($options['artifactMounts']) && is_array($options['artifactMounts'])) {
            foreach ($options['artifactMounts'] as $m) {
                if (is_array($m)) {
                    $artifactMounts[] = $m;
                }
            }
        }
        $git = isset($options['git']) && is_array($options['git']) ? $options['git'] : [];
        $redactions = is_int($options['redactions'] ?? null) ? (int) $options['redactions'] : 0;

        $workspace = $this->workspaces->ensureForSession(
            $sessionId,
            $missionId,
            $runId,
            $prompt,
            $contextFiles,
            $allowedPaths !== [] ? $allowedPaths : ['src/'],
            $projectId,
            $artifactMounts,
            $git,
            $redactions,
        );

        $path = $workspace->rootPath();
        if ($path === '' && $this->legacyExecutionRoot !== null) {
            // Should not happen when store ensureTree runs; keep defensive fallback empty.
            throw new \RuntimeException('Engineering workspace root path missing for ' . $workspace->workspaceId());
        }

        return $path;
    }
}
