<?php

declare(strict_types=1);

namespace Aep\Infrastructure\CodeReview\Adapter;

use Aep\Application\CodeReview\Port\PatchSettingsStore;
use Aep\Application\CodeReview\Service\PatchPipelineService;
use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;
use Aep\Application\EngineeringWorkspace\Service\EngineeringWorkspaceService;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;

/**
 * Additive executor decorator: after successful provider execution, optionally create a Patch.
 * Does not modify Mission Engine or EngineeringExecutionProvider contract.
 */
final class PatchCreationAdapter implements Executor
{
    public function __construct(
        private readonly Executor $inner,
        private readonly PatchPipelineService $patches,
        private readonly PatchSettingsStore $settings,
        private readonly ?ExecutionSessionStore $sessions = null,
        private readonly ?EngineeringWorkspaceService $workspaces = null,
    ) {
    }

    public function id(): string
    {
        return $this->inner->id();
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $result = $this->inner->execute($request);
        $settings = $this->settings->get();
        if (($settings['autoCreatePatchOnExecution'] ?? true) !== true) {
            return $result;
        }
        if (!$result->isSucceeded()) {
            return $result;
        }
        $ctx = $result->context();
        if (($ctx['legacyBypass'] ?? false) === true) {
            return $result;
        }
        $sessionId = is_string($ctx['sessionId'] ?? null) ? (string) $ctx['sessionId'] : null;
        if ($sessionId === null) {
            return $result;
        }

        try {
            $diff = '';
            $workspaceId = null;
            $workspaceRoot = null;
            $git = [];
            if (is_array($ctx['checkpointId'] ?? null)) {
                // ignore
            }
            $runId = is_string($request->contextValue('runId'))
                ? (string) $request->contextValue('runId')
                : (is_string($ctx['runId'] ?? null) ? (string) $ctx['runId'] : 'run_exec');

            if ($this->sessions !== null) {
                $session = $this->sessions->find($sessionId);
                $checkpoint = $session?->checkpoint();
                if (is_array($checkpoint) && is_string($checkpoint['workspacePath'] ?? null)) {
                    $workspaceRoot = (string) $checkpoint['workspacePath'];
                    $diffFile = rtrim($workspaceRoot, '/') . '/RESULT.diff';
                    if (is_file($diffFile)) {
                        $diff = (string) file_get_contents($diffFile);
                    }
                }
            }
            if ($diff === '' && is_array($ctx['filesChanged'] ?? null)) {
                foreach ($ctx['filesChanged'] as $path) {
                    if (is_string($path)) {
                        $diff .= "--- a/{$path}\n+++ b/{$path}\n@@\n+changed\n";
                    }
                }
            }

            if ($this->workspaces !== null) {
                $ws = $this->workspaces->forMission($request->missionId());
                if ($ws !== null) {
                    $workspaceId = $ws->workspaceId();
                    $git = $ws->git();
                    if ($workspaceRoot === null) {
                        $workspaceRoot = $ws->rootPath();
                    }
                    if ($diff === '' && is_file(rtrim((string) $workspaceRoot, '/') . '/RESULT.diff')) {
                        $diff = (string) file_get_contents(rtrim((string) $workspaceRoot, '/') . '/RESULT.diff');
                    }
                }
            }

            $allowed = $request->contextValue('allowedPaths');
            $allowedPaths = [];
            if (is_array($allowed)) {
                foreach ($allowed as $p) {
                    if (is_string($p)) {
                        $allowedPaths[] = $p;
                    }
                }
            }
            $nonGoalsRaw = $request->contextValue('nonGoals');
            $nonGoals = [];
            if (is_array($nonGoalsRaw)) {
                foreach ($nonGoalsRaw as $n) {
                    if (is_string($n)) {
                        $nonGoals[] = $n;
                    }
                }
            }

            $patch = $this->patches->createFromExecution(
                $request->missionId(),
                $runId,
                $diff,
                $sessionId,
                $workspaceId,
                $workspaceRoot,
                $allowedPaths !== [] ? $allowedPaths : ['src/'],
                $nonGoals,
                $git,
            );
            $ctx['patchId'] = $patch->patchId();
            $ctx['patchStatus'] = $patch->status();
            $ctx['mergeReady'] = $patch->mergeReadiness()->ready();

            if ($result->isSucceeded()) {
                return ExecutionResult::succeeded($result->executorId(), $result->message(), $ctx);
            }
        } catch (\Throwable) {
            // Patch creation must not fail the mission execution result.
            return $result;
        }

        return $result;
    }
}
