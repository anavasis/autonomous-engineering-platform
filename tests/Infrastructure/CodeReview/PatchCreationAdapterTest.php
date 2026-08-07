<?php

declare(strict_types=1);

namespace Tests\Infrastructure\CodeReview;

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
use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Infrastructure\CodeReview\Adapter\PatchCreationAdapter;
use Aep\Infrastructure\CodeReview\Provider\ConfigReviewProviderRegistry;
use Aep\Infrastructure\CodeReview\Provider\HeuristicLocalReviewProvider;
use Aep\Infrastructure\CodeReview\Store\FilesystemPatchStore;
use Aep\Infrastructure\CodeReview\Store\JsonPatchSettingsStore;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
use Tests\Support\Assert;

final class PatchCreationAdapterTest
{
    public function test_succeeded_execution_with_no_usable_diff_creates_no_patch(): void
    {
        $root = sys_get_temp_dir() . '/aep_pca_' . bin2hex(random_bytes(4));
        try {
            $workspace = $root . '/ws';
            mkdir($workspace, 0775, true);
            $adapter = $this->buildAdapter($root, $workspace, 'esess_empty');

            $result = $adapter->execute(new ExecutionRequest(
                'msn_pca_1',
                'implement',
                '2026-07-31T12:00:00Z',
                ['runId' => 'run_pca_1', 'allowedPaths' => ['src/']]
            ));

            Assert::true($result->isSucceeded());
            Assert::true(!array_key_exists('patchId', $result->context()));
            Assert::true(!array_key_exists('patchStatus', $result->context()));
            Assert::true(!array_key_exists('mergeReady', $result->context()));
            Assert::same(0, count(glob($root . '/patches/patch_*') ?: []));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_whitespace_only_resolved_diff_creates_no_patch(): void
    {
        $root = sys_get_temp_dir() . '/aep_pca_' . bin2hex(random_bytes(4));
        try {
            $workspace = $root . '/ws';
            mkdir($workspace, 0775, true);
            file_put_contents($workspace . '/RESULT.diff', "   \n\t  \n");
            $adapter = $this->buildAdapter($root, $workspace, 'esess_ws');

            $result = $adapter->execute(new ExecutionRequest(
                'msn_pca_2',
                'implement',
                '2026-07-31T12:00:00Z',
                ['runId' => 'run_pca_2', 'allowedPaths' => ['src/']]
            ));

            Assert::true($result->isSucceeded());
            Assert::true(!array_key_exists('patchId', $result->context()));
            Assert::true(!array_key_exists('mergeReady', $result->context()));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_legitimate_files_changed_fallback_still_creates_patch(): void
    {
        $root = sys_get_temp_dir() . '/aep_pca_' . bin2hex(random_bytes(4));
        try {
            $workspace = $root . '/ws';
            mkdir($workspace, 0775, true);
            $inner = new class implements Executor {
                public function id(): string
                {
                    return 'inner';
                }

                public function execute(ExecutionRequest $request): ExecutionResult
                {
                    return ExecutionResult::succeeded($this->id(), 'ok', [
                        'sessionId' => 'esess_ok',
                        'filesChanged' => ['src/ok.php'],
                    ]);
                }
            };
            $adapter = $this->buildAdapter($root, $workspace, 'esess_ok', $inner);

            $result = $adapter->execute(new ExecutionRequest(
                'msn_pca_3',
                'implement',
                '2026-07-31T12:00:00Z',
                ['runId' => 'run_pca_3', 'allowedPaths' => ['src/']]
            ));

            Assert::true($result->isSucceeded());
            Assert::true(isset($result->context()['patchId']));
            Assert::true(is_string($result->context()['patchId']));
            Assert::true(array_key_exists('mergeReady', $result->context()));
        } finally {
            $this->removeDir($root);
        }
    }

    private function buildAdapter(
        string $root,
        string $workspace,
        string $sessionId,
        ?Executor $inner = null,
    ): PatchCreationAdapter {
        $patchesDir = $root . '/patches';
        $executionDir = $root . '/execution';
        mkdir($patchesDir, 0775, true);
        mkdir($executionDir, 0775, true);

        $settings = new JsonPatchSettingsStore($patchesDir);
        $registry = new ConfigReviewProviderRegistry([
            'providers' => [
                ['id' => 'heuristic-local', 'type' => 'heuristic-local', 'enabled' => true],
            ],
        ], [
            'heuristic-local' => static fn (array $o): HeuristicLocalReviewProvider => new HeuristicLocalReviewProvider($o),
        ]);
        $policies = PatchPolicyFactory::fromSettings($settings->get());
        $pipeline = new PatchPipelineService(
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

        $sessions = new JsonExecutionSessionStore($executionDir);
        $session = new ExecutionSession(
            $sessionId,
            'msn_pca',
            'run_pca',
            'inner',
            ExecutionSession::STATUS_SUCCEEDED,
            Utc::now(),
            Utc::now(),
            'ok',
        );
        $session->setCheckpoint([
            'id' => 'cp_pca',
            'phase' => 'finished',
            'workspacePath' => $workspace,
            'at' => Utc::now(),
            'status' => 'succeeded',
        ]);
        $sessions->save($session);

        $inner ??= new class ($sessionId) implements Executor {
            public function __construct(private string $sessionId)
            {
            }

            public function id(): string
            {
                return 'inner';
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                return ExecutionResult::succeeded($this->id(), 'ok', [
                    'sessionId' => $this->sessionId,
                ]);
            }
        };

        return new PatchCreationAdapter($inner, $pipeline, $settings, $sessions, null);
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
