<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Execution;

use Aep\Application\EngineeringExecution\Service\ArtifactCapture;
use Aep\Application\EngineeringExecution\Service\ContextPackager;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\EngineeringExecution\Service\PromptPipeline;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\Executor;
use Aep\Infrastructure\EngineeringExecution\Provider\LocalAgentProvider;
use Aep\Infrastructure\EngineeringExecution\Registry\ConfigProviderRegistry;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSettingsStore;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\Execution\ProviderRoutingExecutor;
use Tests\Support\Assert;
use Tests\Support\EngineeringWorkspaceTestFactory;

final class ProviderRoutingFailClosedTest
{
    public function test_implement_without_provider_rejects(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_fc_' . bin2hex(random_bytes(4));
        try {
            $router = $this->router($root);
            $result = $router->execute(new ExecutionRequest(
                'msn_fc_1',
                'implement',
                '2026-08-05T12:00:00Z',
                []
            ));
            Assert::true($result->isRejected());
            Assert::true(str_contains($result->message(), 'No real execution provider is configured'));
            Assert::true(($result->context()['legacyBypass'] ?? false) !== true);
            Assert::true(($result->context()['actualExecutorId'] ?? null) !== DeclarativeLocalExecutor::ID);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_implement_never_reaches_declarative_local_implicitly(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_fc2_' . bin2hex(random_bytes(4));
        try {
        $executor = new class implements Executor {
            public bool $called = false;

            public function id(): string
            {
                return DeclarativeLocalExecutor::ID;
            }

            public function execute(\Aep\Application\Execution\ExecutionRequest $request): \Aep\Application\Execution\ExecutionResult
            {
                $this->called = true;

                return (new DeclarativeLocalExecutor())->execute($request);
            }
        };
        $router = $this->router($root, null, $executor);
            $result = $router->execute(new ExecutionRequest(
                'msn_fc_2',
                'implement',
                '2026-08-05T12:00:00Z',
                []
            ));
            Assert::true($result->isRejected());
            Assert::true(!$executor->called);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_explicit_provider_routes_to_orchestrator(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_fc3_' . bin2hex(random_bytes(4));
        try {
            $result = $this->router($root)->execute(new ExecutionRequest(
                'msn_fc_3',
                'implement',
                '2026-08-05T12:00:00Z',
                ['providerId' => 'local-agent', 'runId' => 'run_fc_3']
            ));
            Assert::true($result->isSucceeded());
            Assert::same(ProviderRoutingExecutor::ID, $result->executorId());
            Assert::same('local-agent', $result->context()['routedProviderId'] ?? null);
            Assert::true(isset($result->context()['sessionId']));
            Assert::true(isset($result->context()['workspacePath']));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_non_implement_simulation_still_uses_legacy(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_fc4_' . bin2hex(random_bytes(4));
        try {
            $result = $this->router($root)->execute(new ExecutionRequest(
                'msn_fc_4',
                'probe',
                '2026-08-05T12:00:00Z',
                []
            ));
            Assert::true($result->isSucceeded());
            Assert::same(DeclarativeLocalExecutor::ID, $result->context()['actualExecutorId'] ?? null);
            Assert::true(($result->context()['legacyBypass'] ?? false) === true);
            Assert::same('declarative local execution acknowledged', $result->message());
        } finally {
            $this->removeDir($root);
        }
    }

    private function router(
        string $root,
        ?JsonExecutionSettingsStore $settings = null,
        ?Executor $legacy = null,
    ): ProviderRoutingExecutor {
        $executionDir = $root . '/execution';
        $settings ??= new JsonExecutionSettingsStore($executionDir);
        $sessionStore = new JsonExecutionSessionStore($executionDir);
        $factory = EngineeringWorkspaceTestFactory::make($root);
        $registry = new ConfigProviderRegistry([
            'providers' => [
                ['id' => 'local-agent', 'type' => 'local-agent', 'enabled' => true, 'displayName' => 'Local Agent'],
            ],
        ], [
            'local-agent' => static fn (array $o): LocalAgentProvider => new LocalAgentProvider($o),
        ]);
        $orchestrator = new EngineeringExecutionOrchestrator(
            $registry,
            $sessionStore,
            new PromptPipeline(),
            new ContextPackager(),
            $factory['preparer'],
            new DiffCollector(),
            new ArtifactCapture($factory['artifacts']),
            new ResultNormalizer(),
        );

        return new ProviderRoutingExecutor($legacy ?? new DeclarativeLocalExecutor(), $orchestrator, $settings);
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
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
