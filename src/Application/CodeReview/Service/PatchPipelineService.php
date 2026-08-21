<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Model\ReviewRecord;
use Aep\Application\CodeReview\Port\PatchSettingsStore;
use Aep\Application\CodeReview\Port\PatchStore;
use Aep\Application\MissionControl\Support\Utc;

final class PatchPipelineService
{
    public function __construct(
        private readonly PatchStore $store,
        private readonly PatchSettingsStore $settings,
        private readonly ChangeManifestBuilder $manifests,
        private readonly DiffValidator $diffValidator,
        private readonly StaticAnalysisRunner $staticAnalysis,
        private readonly TestExecutionRunner $tests,
        private readonly PatchScorer $scorer,
        private readonly SelfReviewOrchestrator $selfReview,
        private readonly MergeReadinessEvaluator $readiness,
        private readonly ConflictDetector $conflicts,
    ) {
    }

    /**
     * @param list<string> $allowedPaths
     * @param list<string> $nonGoals
     * @param array<string, mixed> $git
     * @param array<string, string> $ownership
     */
    public function createFromExecution(
        string $missionId,
        string $runId,
        string $diffText,
        ?string $sessionId = null,
        ?string $workspaceId = null,
        ?string $workspaceRoot = null,
        array $allowedPaths = ['src/'],
        array $nonGoals = [],
        array $git = [],
        array $ownership = [],
        bool $runPipeline = true,
    ): Patch {
        $existing = $this->store->findActiveByRun($missionId, $runId);
        if ($existing !== null && !in_array($existing->status(), [
            Patch::STATUS_SUPERSEDED,
            Patch::STATUS_REJECTED,
            Patch::STATUS_FAILED,
            Patch::STATUS_ROLLED_BACK,
            Patch::STATUS_SEALED,
        ], true)) {
            $existing->setStatus(Patch::STATUS_SUPERSEDED, Utc::now(), 'Superseded by new patch');
            $this->store->save($existing);
            $this->event($existing->patchId(), 'patch.superseded', []);
        }

        $settings = $this->settings->get();
        if ($ownership === [] && isset($settings['ownership']) && is_array($settings['ownership'])) {
            foreach ($settings['ownership'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $ownership[$k] = $v;
                }
            }
        }

        $now = Utc::now();
        $patchId = 'patch_' . bin2hex(random_bytes(8));
        $patch = new Patch(
            $patchId,
            $missionId,
            $runId,
            Patch::STATUS_DRAFT,
            $now,
            $now,
            $diffText,
            $workspaceId,
            $sessionId,
            allowedPaths: $allowedPaths,
            compatibility: [
                'aepMinVersion' => '0.5.0',
                'executionContract' => 'EngineeringExecutionProvider@0.3',
                'workspacePlane' => 'EngineeringWorkspace@0.4',
                'missionFsm' => 'MissionDomain@1',
                'schemaVersion' => Patch::SCHEMA_VERSION,
            ],
        );
        $this->store->save($patch);
        $this->event($patchId, 'patch.created', ['missionId' => $missionId, 'runId' => $runId]);

        $patch->setStatus(Patch::STATUS_COLLECTING, Utc::now(), 'Building manifest');
        $manifest = $this->manifests->build($diffText, $allowedPaths, $nonGoals, $ownership, $git);
        $patch->setManifest($manifest);
        $patch->setRollback([
            'previousPatchId' => $existing?->patchId(),
            'workspaceId' => $workspaceId,
            'baseSha' => $manifest->baseSha(),
            'headSha' => $manifest->headSha(),
        ]);
        $this->rehash($patch);
        $this->store->save($patch);

        if ($runPipeline) {
            return $this->runPipeline($patchId, $workspaceRoot);
        }

        return $this->require($patchId);
    }

    public function runPipeline(string $patchId, ?string $workspaceRoot = null): Patch
    {
        $patch = $this->require($patchId);
        $patch->setStatus(Patch::STATUS_VALIDATING, Utc::now(), 'Running checks');
        $this->store->save($patch);

        $checks = [];
        $checks[] = $this->diffValidator->validate($patch->diffText(), $patch->manifest());
        $this->event($patchId, 'checks.diff', ['status' => $checks[0]->status()]);
        $checks[] = $this->staticAnalysis->run($patch->manifest(), $workspaceRoot);
        $this->event($patchId, 'checks.static', ['status' => $checks[1]->status()]);
        $checks[] = $this->tests->run($workspaceRoot);
        $this->event($patchId, 'checks.tests', ['status' => $checks[2]->status()]);
        $patch->setChecks($checks);

        $scored = $this->scorer->score($patch);
        $patch->setScore($scored['score'], $scored['grade']);

        $patch = $this->conflicts->detect($patch, $workspaceRoot);
        if (in_array($patch->status(), [Patch::STATUS_CONFLICTED, Patch::STATUS_STALE], true)) {
            $this->rehash($patch);
            $this->store->save($patch);
            $this->event($patchId, 'patch.conflict', ['status' => $patch->status()]);

            return $patch;
        }

        $patch->setStatus(Patch::STATUS_UNDER_REVIEW, Utc::now(), 'Requesting reviews');
        $this->store->save($patch);

        $settings = $this->settings->get();
        $providerIds = [];
        if (isset($settings['reviewProviders']) && is_array($settings['reviewProviders'])) {
            foreach ($settings['reviewProviders'] as $id) {
                if (is_string($id)) {
                    $providerIds[] = $id;
                }
            }
        }
        $reviews = $this->selfReview->review($patch, $providerIds !== [] ? $providerIds : null);
        foreach ($reviews as $review) {
            $patch->addReview($review);
            $this->event($patchId, 'review.received', [
                'providerId' => $review->providerId(),
                'verdict' => $review->verdict(),
            ]);
        }

        $scored = $this->scorer->score($patch);
        $patch->setScore($scored['score'], $scored['grade']);

        $hasReject = false;
        $hasChanges = false;
        foreach ($patch->reviews() as $review) {
            if ($review->verdict() === 'reject') {
                $hasReject = true;
            }
            if ($review->verdict() === 'request_changes') {
                $hasChanges = true;
            }
        }
        if ($hasReject) {
            $patch->setStatus(Patch::STATUS_REJECTED, Utc::now(), 'Rejected by review');
        } elseif ($hasChanges) {
            $patch->setStatus(Patch::STATUS_CHANGES_REQUESTED, Utc::now(), 'Changes requested');
        } elseif ($this->hasProviderApprove($patch)) {
            $patch->setStatus(Patch::STATUS_APPROVED, Utc::now(), 'Provider reviews approved');
        }

        $readiness = $this->readiness->evaluate($patch);
        $patch->setMergeReadiness($readiness);
        if ($readiness->ready() && $patch->status() === Patch::STATUS_APPROVED) {
            $patch->setStatus(Patch::STATUS_MERGE_READY, Utc::now(), 'Merge ready');
        }

        $this->rehash($patch);
        $this->store->save($patch);
        $this->event($patchId, 'patch.pipeline_complete', [
            'status' => $patch->status(),
            'score' => $patch->score(),
            'ready' => $readiness->ready(),
        ]);

        return $patch;
    }

    public function humanApprove(string $patchId, string $actor, string $summary = 'Approved by human'): Patch
    {
        $patch = $this->require($patchId);
        $review = new ReviewRecord(
            'rev_human_' . bin2hex(random_bytes(4)),
            'human',
            'approve',
            $summary,
            [],
            5,
            Utc::now(),
            $actor,
        );
        $patch->addReview($review);
        $patch->setStatus(Patch::STATUS_APPROVED, Utc::now(), $summary);
        $scored = $this->scorer->score($patch);
        $patch->setScore($scored['score'], $scored['grade']);
        $readiness = $this->readiness->evaluate($patch);
        $patch->setMergeReadiness($readiness);
        if ($readiness->ready()) {
            $patch->setStatus(Patch::STATUS_MERGE_READY, Utc::now(), 'Merge ready');
        }
        $this->rehash($patch);
        $this->store->save($patch);
        $this->event($patchId, 'review.human_approve', ['actor' => $actor]);

        return $patch;
    }

    public function humanReject(string $patchId, string $actor, string $summary = 'Rejected', bool $requestChanges = false): Patch
    {
        $patch = $this->require($patchId);
        $verdict = $requestChanges ? 'request_changes' : 'reject';
        $review = new ReviewRecord(
            'rev_human_' . bin2hex(random_bytes(4)),
            'human',
            $verdict,
            $summary,
            [['severity' => 'error', 'path' => null, 'message' => $summary]],
            -10,
            Utc::now(),
            $actor,
        );
        $patch->addReview($review);
        $patch->setStatus(
            $requestChanges ? Patch::STATUS_CHANGES_REQUESTED : Patch::STATUS_REJECTED,
            Utc::now(),
            $summary
        );
        $patch->setMergeReadiness($this->readiness->evaluate($patch));
        $this->rehash($patch);
        $this->store->save($patch);
        $this->event($patchId, 'review.human_' . $verdict, ['actor' => $actor]);

        return $patch;
    }

    public function seal(string $patchId): Patch
    {
        $patch = $this->require($patchId);
        $patch->setStatus(Patch::STATUS_SEALED, Utc::now(), 'Sealed');
        $this->rehash($patch);
        $this->store->save($patch);
        $this->event($patchId, 'patch.sealed', []);

        return $patch;
    }

    public function evaluateReadiness(string $patchId): Patch
    {
        $patch = $this->require($patchId);
        $readiness = $this->readiness->evaluate($patch);
        $patch->setMergeReadiness($readiness);
        if ($readiness->ready() && in_array($patch->status(), [Patch::STATUS_APPROVED, Patch::STATUS_MERGE_READY], true)) {
            $patch->setStatus(Patch::STATUS_MERGE_READY, Utc::now(), 'Merge ready');
        }
        $this->store->save($patch);

        return $patch;
    }

    public function get(string $patchId): ?Patch
    {
        return $this->store->find($patchId);
    }

    /** @return list<Patch> */
    public function list(?string $missionId = null, ?string $status = null, ?bool $mergeReady = null): array
    {
        return $this->store->list($missionId, $status, $mergeReady);
    }

    /** @return list<array<string, mixed>> */
    public function timeline(string $patchId): array
    {
        return $this->store->timeline($patchId);
    }

    private function hasProviderApprove(Patch $patch): bool
    {
        foreach ($patch->reviews() as $review) {
            if ($review->isApprove()) {
                return true;
            }
        }

        return false;
    }

    private function rehash(Patch $patch): void
    {
        $canonical = json_encode([
            'manifest' => $patch->manifest()->toArray(),
            'diffHash' => $patch->manifest()->diffHash(),
            'checks' => array_map(static fn ($c) => $c->toArray()['digest'] ?? '', $patch->checks()),
            'schemaVersion' => Patch::SCHEMA_VERSION,
        ], JSON_THROW_ON_ERROR);
        $patch->setReproFingerprint('sha256:' . hash('sha256', $canonical));
        $integrity = json_encode($patch->toArray(), JSON_THROW_ON_ERROR) . "\n" . $patch->diffText();
        $patch->setIntegrityHash('sha256:' . hash('sha256', $integrity));
    }

    private function require(string $patchId): Patch
    {
        $patch = $this->store->find($patchId);
        if ($patch === null) {
            throw new \InvalidArgumentException('Unknown patch: ' . $patchId);
        }

        return $patch;
    }

    /** @param array<string, mixed> $data */
    private function event(string $patchId, string $type, array $data): void
    {
        $this->store->appendTimeline($patchId, [
            'at' => Utc::now(),
            'type' => $type,
            'data' => $data,
        ]);
    }
}
