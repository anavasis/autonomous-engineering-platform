<?php

declare(strict_types=1);

namespace Tests\Application\EngineeringExecution;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Model\UsageMetrics;
use Aep\Application\EngineeringExecution\Service\ArtifactCapture;
use Aep\Application\EngineeringExecution\Service\ContextPackager;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\EngineeringExecution\Service\PromptPipeline;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Infrastructure\EngineeringExecution\Provider\AbstractBufferedProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\LocalAgentProvider;
use Aep\Infrastructure\EngineeringExecution\Registry\ConfigProviderRegistry;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
use Tests\Support\Assert;
use Tests\Support\EngineeringWorkspaceTestFactory;

final class EngineeringExecutionOrchestratorTest
{
    public function test_local_agent_provider_completes_with_artifacts_and_metrics(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root);
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_1',
                'implement',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'local-agent',
                    'runId' => 'run_ee_1',
                    'objective' => 'Add a local agent change file',
                    'allowedPaths' => ['src/'],
                    'contextFiles' => [
                        'src/existing.txt' => 'hello',
                    ],
                ]
            ));

            Assert::true($result->isSucceeded());
            Assert::true(isset($result->context()['sessionId']));
            Assert::same('local-agent', $result->context()['providerId'] ?? null);
            Assert::true(is_array($result->context()['usage'] ?? null));
            Assert::true(($result->context()['usage']['totalTokens'] ?? 0) > 0);
            Assert::true(is_array($result->context()['artifacts'] ?? null));
            Assert::true(isset($result->context()['artifacts']['report.execution.prompt']));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_unknown_provider_fails_cleanly(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root);
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_2',
                'implement',
                '2026-07-31T12:00:00Z',
                ['providerId' => 'does-not-exist', 'runId' => 'run_ee_2']
            ));
            Assert::true($result->isFailed() || $result->isRejected());
            Assert::true(str_contains($result->message(), 'Unknown execution provider'));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_retry_then_success_path_records_retries(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root);
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_3',
                'implement',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'local-agent',
                    'runId' => 'run_ee_3',
                    'providerOptions' => ['forceFail' => false],
                ]
            ));
            Assert::true($result->isSucceeded());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_implement_with_empty_collected_diff_fails_closed(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator, $store] = $this->build($root, $this->emptySuccessFactories());
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_empty',
                'implement',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'empty-success',
                    'runId' => 'run_ee_empty',
                    'objective' => 'Should fail without collected evidence',
                    'allowedPaths' => ['src/'],
                ]
            ));

            Assert::true($result->isFailed());
            Assert::same('Implementation produced no collected file changes.', $result->message());
            Assert::same([], $result->context()['filesChanged'] ?? null);
            Assert::same(0, $result->context()['usage']['filesChanged'] ?? -1);
            Assert::true(($result->context()['usage']['totalTokens'] ?? 0) > 0);
            Assert::true(isset($result->context()['artifacts']['report.execution.prompt']));

            $sessionId = (string) ($result->context()['sessionId'] ?? '');
            $session = $store->find($sessionId);
            Assert::true($session instanceof ExecutionSession);
            Assert::same(ExecutionSession::STATUS_FAILED, $session->status());
            Assert::same(0, $session->usage()->toArray()['filesChanged']);
            Assert::same(ProviderResult::FAILED, $session->checkpoint()['status'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_provider_declared_files_cannot_bypass_empty_implement_gate(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root, $this->emptySuccessFactories(['src/claimed.php']));
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_claimed',
                'implement',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'empty-success',
                    'runId' => 'run_ee_claimed',
                    'allowedPaths' => ['src/'],
                ]
            ));

            Assert::true($result->isFailed());
            Assert::same('Implementation produced no collected file changes.', $result->message());
            Assert::same([], $result->context()['filesChanged'] ?? null);
            Assert::same(0, $result->context()['usage']['filesChanged'] ?? -1);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_probe_with_empty_diff_may_still_succeed(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root, $this->emptySuccessFactories(['src/claimed.php']));
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_probe',
                'probe',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'empty-success',
                    'runId' => 'run_ee_probe',
                    'allowedPaths' => ['src/'],
                ]
            ));

            Assert::true($result->isSucceeded());
            Assert::same(['src/claimed.php'], $result->context()['filesChanged'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_real_implement_with_collected_diff_succeeds(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root);
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_real',
                'implement',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'local-agent',
                    'runId' => 'run_ee_real',
                    'objective' => 'Real collected change',
                    'allowedPaths' => ['src/'],
                ]
            ));

            Assert::true($result->isSucceeded());
            $files = $result->context()['filesChanged'] ?? null;
            Assert::true(is_array($files) && $files !== []);
            Assert::true(($result->context()['usage']['filesChanged'] ?? 0) >= 1);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_request_objective_appears_exactly_in_generated_prompt(): void
    {
        $root = sys_get_temp_dir() . '/aep_ee_' . bin2hex(random_bytes(4));
        try {
            [$orchestrator] = $this->build($root);
            $exact = 'Exact objective with  internal  spacing';
            $result = $orchestrator->execute(new ExecutionRequest(
                'msn_ee_obj',
                'implement',
                '2026-07-31T12:00:00Z',
                [
                    'providerId' => 'local-agent',
                    'runId' => 'run_ee_obj',
                    'objective' => $exact,
                    'allowedPaths' => ['src/'],
                ]
            ));

            Assert::true($result->isSucceeded());
            $workspace = (string) ($result->context()['workspacePath'] ?? '');
            Assert::true($workspace !== '' && is_file($workspace . '/PROMPT.md'));
            $promptBody = (string) file_get_contents($workspace . '/PROMPT.md');
            Assert::true(str_contains($promptBody, 'Objective: ' . $exact));
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @param array<string, callable> $extraFactories
     * @return array{0: EngineeringExecutionOrchestrator, 1: JsonExecutionSessionStore}
     */
    private function build(string $root, array $extraFactories = []): array
    {
        $executionDir = $root . '/execution';
        if (!is_dir($executionDir)) {
            mkdir($executionDir, 0775, true);
        }
        $factory = EngineeringWorkspaceTestFactory::make($root);
        $providers = [
            ['id' => 'local-agent', 'type' => 'local-agent', 'enabled' => true],
        ];
        $factories = [
            'local-agent' => static fn (array $o): LocalAgentProvider => new LocalAgentProvider($o),
        ];
        foreach ($extraFactories as $type => $factoryFn) {
            $providers[] = ['id' => $type, 'type' => $type, 'enabled' => true];
            $factories[$type] = $factoryFn;
        }
        $registry = new ConfigProviderRegistry([
            'providers' => $providers,
        ], $factories);
        $store = new JsonExecutionSessionStore($executionDir);

        return [
            new EngineeringExecutionOrchestrator(
                $registry,
                $store,
                new PromptPipeline(),
                new ContextPackager(),
                $factory['preparer'],
                new DiffCollector(),
                new ArtifactCapture($factory['artifacts']),
                new ResultNormalizer(),
            ),
            $store,
        ];
    }

    /**
     * @param list<string> $declaredFiles
     * @return array<string, callable>
     */
    private function emptySuccessFactories(array $declaredFiles = []): array
    {
        return [
            'empty-success' => static function () use ($declaredFiles): AbstractBufferedProvider {
                return new class ($declaredFiles) extends AbstractBufferedProvider {
                    /** @param list<string> $declaredFiles */
                    public function __construct(private array $declaredFiles)
                    {
                        parent::__construct(
                            'empty-success',
                            'Empty Success',
                            new ProviderCapabilities(
                                streaming: true,
                                cancel: true,
                                resume: false,
                                workspaceMount: true,
                                diffExport: true,
                                tools: false,
                                maxContextTokens: 8000,
                                supportsImages: false,
                                costReporting: true,
                                parallelSessions: true,
                            )
                        );
                    }

                    public function health(): ProviderHealth
                    {
                        return new ProviderHealth('ok', 'ready', Utc::now());
                    }

                    protected function run(ProviderSessionRequest $request): void
                    {
                        $usage = new UsageMetrics(42, 17, 0.001, 0.02, 0, 0, count($this->declaredFiles));
                        $this->complete(
                            $request->sessionId(),
                            new ProviderResult(
                                ProviderResult::SUCCEEDED,
                                'provider claimed success without workspace mutation',
                                $this->declaredFiles,
                                "diagnostic provider diff text\n",
                                $usage,
                                ['diag' => 'raw-meta-preserved', 'provider' => 'empty-success']
                            )
                        );
                    }
                };
            },
        ];
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
