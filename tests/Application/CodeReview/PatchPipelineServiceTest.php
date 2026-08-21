<?php

declare(strict_types=1);

namespace Tests\Application\CodeReview;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Policy\PatchPolicyFactory;
use Aep\Application\CodeReview\Service\ChangeManifestBuilder;
use Aep\Application\CodeReview\Service\ConflictDetector;
use Aep\Application\CodeReview\Service\DiffValidator;
use Aep\Application\CodeReview\Service\MergeReadinessEvaluator;
use Aep\Application\CodeReview\Service\PatchPipelineService;
use Aep\Application\CodeReview\Service\PatchScorer;
use Aep\Application\CodeReview\Service\SelfReviewOrchestrator;
use Aep\Application\CodeReview\Service\StaticAnalysisRunner;
use Aep\Application\CodeReview\Service\TestExecutionRunner;
use Aep\Infrastructure\CodeReview\Provider\ConfigReviewProviderRegistry;
use Aep\Infrastructure\CodeReview\Provider\HeuristicLocalReviewProvider;
use Aep\Infrastructure\CodeReview\Store\FilesystemPatchStore;
use Aep\Infrastructure\CodeReview\Store\JsonPatchSettingsStore;
use Tests\Support\Assert;

final class PatchPipelineServiceTest
{
    public function test_create_pipeline_produces_fingerprint_and_checks(): void
    {
        $root = sys_get_temp_dir() . '/aep_patch_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->build($root);
            $diff = "--- a/src/foo.php\n+++ b/src/foo.php\n@@\n+echo 1;\n";
            $patch = $pipeline->createFromExecution('msn_1', 'run_1', $diff);

            Assert::true(str_starts_with($patch->patchId(), 'patch_'));
            Assert::same(1, $patch->toArray()['schemaVersion'] ?? null);
            Assert::true(str_starts_with($patch->reproFingerprint(), 'sha256:'));
            Assert::true(str_starts_with($patch->integrityHash(), 'sha256:'));
            Assert::true(isset($patch->toArray()['compatibility']['executionContract']));
            Assert::true(count($patch->checks()) >= 3);
            Assert::true(count($patch->reviews()) >= 1);
            Assert::true($patch->score() > 0);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_secret_in_diff_fails_validation(): void
    {
        $root = sys_get_temp_dir() . '/aep_patch_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->build($root);
            $diff = "--- a/src/a.php\n+++ b/src/a.php\n@@\n+api_key=supersecretvalue\n";
            $patch = $pipeline->createFromExecution('msn_2', 'run_2', $diff);
            $diffCheck = null;
            foreach ($patch->checks() as $check) {
                if ($check->kind() === 'diff') {
                    $diffCheck = $check;
                }
            }
            Assert::true($diffCheck !== null);
            Assert::same('failed', $diffCheck?->status());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_human_approve_updates_readiness(): void
    {
        $root = sys_get_temp_dir() . '/aep_patch_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->build($root, ['requireHumanApproval' => true, 'minScore' => 50]);
            $diff = "--- a/src/ok.php\n+++ b/src/ok.php\n@@\n+return true;\n";
            $patch = $pipeline->createFromExecution('msn_3', 'run_3', $diff);
            $approved = $pipeline->humanApprove($patch->patchId(), 'approver', 'LGTM');
            Assert::true(in_array($approved->status(), [Patch::STATUS_APPROVED, Patch::STATUS_MERGE_READY], true));
            Assert::true(count($approved->reviews()) >= 2);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_empty_diff_validation_fails_and_is_not_merge_ready(): void
    {
        $root = sys_get_temp_dir() . '/aep_patch_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->build($root);
            $patch = $pipeline->createFromExecution('msn_empty', 'run_empty', '');
            $diffCheck = null;
            foreach ($patch->checks() as $check) {
                if ($check->kind() === 'diff') {
                    $diffCheck = $check;
                }
            }
            Assert::true($diffCheck !== null);
            Assert::same('failed', $diffCheck?->status());
            $hasEmptyError = false;
            foreach (($diffCheck?->toArray()['findings'] ?? []) as $finding) {
                if (!is_array($finding)) {
                    continue;
                }
                if (($finding['severity'] ?? null) === 'error' && ($finding['message'] ?? null) === 'Empty diff') {
                    $hasEmptyError = true;
                }
            }
            Assert::true($hasEmptyError);
            Assert::true($patch->status() !== Patch::STATUS_MERGE_READY);
            Assert::true($patch->mergeReadiness()->ready() !== true);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_whitespace_only_diff_validation_fails_and_is_not_merge_ready(): void
    {
        $root = sys_get_temp_dir() . '/aep_patch_' . bin2hex(random_bytes(4));
        try {
            $pipeline = $this->build($root);
            $patch = $pipeline->createFromExecution('msn_ws', 'run_ws', "  \n\t\n");
            $diffCheck = null;
            foreach ($patch->checks() as $check) {
                if ($check->kind() === 'diff') {
                    $diffCheck = $check;
                }
            }
            Assert::true($diffCheck !== null);
            Assert::same('failed', $diffCheck?->status());
            Assert::true($patch->status() !== Patch::STATUS_MERGE_READY);
            Assert::true($patch->mergeReadiness()->ready() !== true);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @param array<string, mixed> $settingsPatch
     */
    private function build(string $root, array $settingsPatch = []): PatchPipelineService
    {
        $patchesDir = $root . '/patches';
        mkdir($patchesDir, 0775, true);
        $settings = new JsonPatchSettingsStore($patchesDir);
        if ($settingsPatch !== []) {
            $settings->update($settingsPatch);
        }
        $registry = new ConfigReviewProviderRegistry([
            'providers' => [
                ['id' => 'heuristic-local', 'type' => 'heuristic-local', 'enabled' => true],
            ],
        ], [
            'heuristic-local' => static fn (array $o): HeuristicLocalReviewProvider => new HeuristicLocalReviewProvider($o),
        ]);
        $policies = PatchPolicyFactory::fromSettings($settings->get());

        return new PatchPipelineService(
            new FilesystemPatchStore($patchesDir),
            $settings,
            new ChangeManifestBuilder(),
            new DiffValidator(),
            new StaticAnalysisRunner(),
            new TestExecutionRunner(),
            new PatchScorer(),
            new SelfReviewOrchestrator($registry),
            new MergeReadinessEvaluator($policies),
            new ConflictDetector(),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
