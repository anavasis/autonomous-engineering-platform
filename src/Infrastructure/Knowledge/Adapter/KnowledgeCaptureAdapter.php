<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Adapter;

use Aep\Application\CodeReview\Service\PatchPipelineService;
use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;
use Aep\Application\Knowledge\Port\KnowledgeSettingsStore;
use Aep\Application\Knowledge\Service\KnowledgeCaptureService;

/**
 * Best-effort capture of execution / patch / workspace memories after EE completes.
 * Must never fail the originating execution.
 */
final class KnowledgeCaptureAdapter implements Executor
{
    public function __construct(
        private readonly Executor $inner,
        private readonly KnowledgeCaptureService $capture,
        private readonly KnowledgeSettingsStore $settings,
        private readonly ?ExecutionSessionStore $sessions = null,
        private readonly ?PatchPipelineService $patches = null,
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
        if (($settings['autoCaptureOnExecution'] ?? true) !== true) {
            return $result;
        }

        try {
            $ctx = $result->context();
            $sessionId = is_string($ctx['sessionId'] ?? null) ? (string) $ctx['sessionId'] : null;
            $runId = is_string($request->contextValue('runId'))
                ? (string) $request->contextValue('runId')
                : (is_string($ctx['runId'] ?? null) ? (string) $ctx['runId'] : 'run_exec');
            $projectId = is_string($request->contextValue('projectId'))
                ? (string) $request->contextValue('projectId')
                : null;
            $objective = '';
            foreach (['objective', 'summary', 'planSummary'] as $key) {
                $v = $request->contextValue($key);
                if (is_string($v) && $v !== '') {
                    $objective = $v;
                    break;
                }
            }
            $workspaceId = null;
            $paths = [];
            if (is_array($request->contextValue('allowedPaths'))) {
                foreach ($request->contextValue('allowedPaths') as $p) {
                    if (is_string($p)) {
                        $paths[] = $p;
                    }
                }
            }
            if ($sessionId !== null && $this->sessions !== null) {
                $session = $this->sessions->find($sessionId);
                $checkpoint = $session?->checkpoint();
                if (is_array($checkpoint) && is_string($checkpoint['workspaceId'] ?? null)) {
                    $workspaceId = (string) $checkpoint['workspaceId'];
                }
            }
            $patchId = null;
            if ($this->patches !== null) {
                $active = null;
                foreach ($this->patches->list($request->missionId()) as $candidate) {
                    if ($candidate->runId() === $runId) {
                        $active = $candidate;
                        break;
                    }
                }
                if ($active === null) {
                    $listed = $this->patches->list($request->missionId());
                    $active = $listed[0] ?? null;
                }
                if ($active !== null) {
                    $patchId = $active->patchId();
                    $this->capture->capturePatchEvent($patchId, 'execution.associated', $active->toArray());
                    if (in_array($active->status(), ['merge_ready', 'sealed', 'approved'], true)) {
                        $this->capture->capturePatchEvent($patchId, 'patch.' . $active->status(), $active->toArray());
                    }
                    foreach ($active->reviews() as $review) {
                        $this->capture->capturePatchEvent($patchId, 'review.' . $review->verdict(), [
                            'missionId' => $active->missionId(),
                            'runId' => $active->runId(),
                            'status' => $active->status(),
                            'score' => $active->score(),
                            'grade' => $active->grade(),
                        ]);
                    }
                    foreach ($active->checks() as $check) {
                        if ($check->status() === 'failed') {
                            $this->capture->capturePatchEvent($patchId, 'validation.' . $check->kind(), [
                                'missionId' => $active->missionId(),
                                'runId' => $active->runId(),
                                'status' => $check->status(),
                            ]);
                        }
                    }
                }
            }

            $this->capture->captureExecutionOutcome(
                $request->missionId(),
                $runId,
                $result->isSucceeded(),
                $objective,
                $projectId,
                $sessionId,
                $workspaceId,
                $patchId,
                $paths,
            );
        } catch (\Throwable) {
            // never fail mission/execution due to knowledge capture
        }

        return $result;
    }
}
