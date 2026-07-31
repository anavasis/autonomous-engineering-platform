<?php

declare(strict_types=1);

namespace Tests\Infrastructure\EngineeringExecution;

use Aep\Application\EngineeringExecution\Service\ArtifactCapture;
use Aep\Application\EngineeringExecution\Service\ContextPackager;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\EngineeringExecution\Service\PromptPipeline;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionService;
use Aep\Infrastructure\EngineeringExecution\Provider\LocalAgentProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\StubCliProvider;
use Aep\Infrastructure\EngineeringExecution\Registry\ConfigProviderRegistry;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSessionStore;
use Aep\Infrastructure\EngineeringExecution\Store\JsonExecutionSettingsStore;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\Execution\ProviderRoutingExecutor;
use Tests\Support\Assert;
use Tests\Support\EngineeringWorkspaceTestFactory;

final class ProviderRoutingExecutorTest
{
    public function test_no_provider_keeps_legacy_local_behavior(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_' . bin2hex(random_bytes(4));
        try {
            $service = new ExecutionService($this->router($root));
            $result = $service->execute(new ExecutionRequest(
                'msn_legacy',
                'implement',
                '2026-07-31T12:00:00Z',
                []
            ));
            Assert::true($result->isSucceeded());
            Assert::same(ProviderRoutingExecutor::ID, $result->executorId());
            Assert::same(DeclarativeLocalExecutor::ID, $result->context()['actualExecutorId'] ?? null);
            Assert::same('declarative local execution acknowledged', $result->message());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_provider_id_routes_to_orchestrator(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_' . bin2hex(random_bytes(4));
        try {
            $router = $this->router($root);
            $result = $router->execute(new ExecutionRequest(
                'msn_prov',
                'implement',
                '2026-07-31T12:00:00Z',
                ['providerId' => 'local-agent', 'runId' => 'run_p1']
            ));
            Assert::true($result->isSucceeded());
            Assert::same(ProviderRoutingExecutor::ID, $result->executorId());
            Assert::same('local-agent', $result->context()['routedProviderId'] ?? null);
            Assert::true(isset($result->context()['sessionId']));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_config_driven_stub_providers_are_discoverable(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_' . bin2hex(random_bytes(4));
        try {
            $registry = $this->registry();
            Assert::true($registry->has('cursor'));
            Assert::true($registry->has('codex'));
            Assert::true($registry->has('claude-code'));
            Assert::true($registry->has('gemini-cli'));
            Assert::same('Cursor Agent', $registry->get('cursor')->displayName());
            $health = $registry->get('cursor')->health();
            Assert::true($health->isAvailable());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_default_provider_from_settings_routes_without_explicit_context(): void
    {
        $root = sys_get_temp_dir() . '/aep_route_' . bin2hex(random_bytes(4));
        try {
            $settings = new JsonExecutionSettingsStore($root . '/execution');
            $settings->update(['defaultProviderId' => 'local-agent']);
            $router = $this->router($root, $settings);
            $result = $router->execute(new ExecutionRequest(
                'msn_default',
                'implement',
                '2026-07-31T12:00:00Z',
                ['runId' => 'run_default']
            ));
            Assert::true($result->isSucceeded());
            Assert::same(ProviderRoutingExecutor::ID, $result->executorId());
            Assert::same('local-agent', $result->context()['routedProviderId'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    private function router(string $root, ?JsonExecutionSettingsStore $settings = null): ProviderRoutingExecutor
    {
        $executionDir = $root . '/execution';
        if (!is_dir($executionDir)) {
            mkdir($executionDir, 0775, true);
        }
        $settings ??= new JsonExecutionSettingsStore($executionDir);
        $factory = EngineeringWorkspaceTestFactory::make($root);
        $orchestrator = new EngineeringExecutionOrchestrator(
            $this->registry(),
            new JsonExecutionSessionStore($executionDir),
            new PromptPipeline(),
            new ContextPackager(),
            $factory['preparer'],
            new DiffCollector(),
            new ArtifactCapture($factory['artifacts']),
            new ResultNormalizer(),
        );

        return new ProviderRoutingExecutor(new DeclarativeLocalExecutor(), $orchestrator, $settings);
    }

    private function registry(): ConfigProviderRegistry
    {
        return new ConfigProviderRegistry([
            'providers' => [
                ['id' => 'local-agent', 'type' => 'local-agent', 'enabled' => true],
                ['id' => 'cursor', 'type' => 'stub-cli', 'enabled' => true, 'displayName' => 'Cursor Agent', 'options' => ['simulate' => true]],
                ['id' => 'codex', 'type' => 'stub-cli', 'enabled' => true, 'displayName' => 'OpenAI Codex', 'options' => ['simulate' => true]],
                ['id' => 'claude-code', 'type' => 'stub-cli', 'enabled' => true, 'displayName' => 'Claude Code', 'options' => ['simulate' => true]],
                ['id' => 'gemini-cli', 'type' => 'stub-cli', 'enabled' => true, 'displayName' => 'Gemini CLI', 'options' => ['simulate' => true]],
            ],
        ], [
            'local-agent' => static fn (array $o): LocalAgentProvider => new LocalAgentProvider($o),
            'stub-cli' => static fn (array $o): StubCliProvider => new StubCliProvider($o),
        ]);
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
