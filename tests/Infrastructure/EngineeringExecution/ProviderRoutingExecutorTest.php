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
use Aep\Infrastructure\EngineeringExecution\Provider\ClaudeCodeCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\CodexCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\CursorCliProvider;
use Aep\Infrastructure\EngineeringExecution\Provider\ExternalCliProvider;
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
        $bin = $root . '/fake-cursor.sh';
        try {
            if (!is_dir($root)) {
                mkdir($root, 0775, true);
            }
            file_put_contents($bin, "#!/bin/sh\nexit 0\n");
            chmod($bin, 0755);

            $registry = $this->registry($bin);
            Assert::true($registry->has('cursor'));
            Assert::true($registry->has('codex'));
            Assert::true($registry->has('claude-code'));
            Assert::true($registry->has('gemini-cli'));
            Assert::same('Cursor Agent', $registry->get('cursor')->displayName());
            Assert::true($registry->get('cursor') instanceof CursorCliProvider);
            Assert::true($registry->get('cursor') instanceof ExternalCliProvider);
            Assert::true($registry->get('claude-code') instanceof ClaudeCodeCliProvider);
            Assert::true($registry->get('claude-code') instanceof ExternalCliProvider);
            Assert::true($registry->get('codex') instanceof CodexCliProvider);
            Assert::true($registry->get('codex') instanceof ExternalCliProvider);
            $health = $registry->get('cursor')->health();
            Assert::true($health->isAvailable());
            Assert::true($registry->get('claude-code')->health()->isAvailable());
            Assert::true($registry->get('codex')->health()->isAvailable());
            Assert::same('degraded', $registry->get('gemini-cli')->health()->status());

            $ext = new ConfigProviderRegistry([
                'providers' => [
                    ['id' => 'future_cli', 'type' => 'external-cli', 'enabled' => true, 'displayName' => 'Future', 'options' => ['binary' => $bin]],
                ],
            ], [
                'external-cli' => static fn (array $o): ExternalCliProvider => new ExternalCliProvider($o),
            ]);
            Assert::true($ext->has('future_cli'));
            Assert::same('ok', $ext->get('future_cli')->health()->status());
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

    public function test_cursor_cli_provider_reference_suite(): void
    {
        // CursorCliProviderTest is invoked here so the suite runs without changing tests/run.php.
        require_once __DIR__ . '/CursorCliProviderTest.php';
        $suite = new CursorCliProviderTest();
        $suite->test_health_unavailable_when_binary_missing();
        $suite->test_health_ok_when_binary_executable();
        $suite->test_run_captures_streams_and_leaves_workspace_for_diffcollector();
        $suite->test_nonzero_exit_fails_without_mutating_provider_side_files();
        $suite->test_uses_repo_subdirectory_when_configured_and_present();
    }

    public function test_external_cli_provider_extract_suite(): void
    {
        require_once __DIR__ . '/ExternalCliProviderTest.php';
        $suite = new ExternalCliProviderTest();
        $suite->test_generic_external_cli_run_and_events();
        $suite->test_cursor_provider_is_thin_external_subclass();
    }

    public function test_claude_code_cli_provider_suite(): void
    {
        require_once __DIR__ . '/ClaudeCodeCliProviderTest.php';
        $suite = new ClaudeCodeCliProviderTest();
        $suite->test_health_unavailable_when_binary_missing();
        $suite->test_run_uses_print_flag_and_prompt_argv();
        $suite->test_json_args_remain_configurable();
    }

    public function test_codex_cli_provider_suite(): void
    {
        require_once __DIR__ . '/CodexCliProviderTest.php';
        $suite = new CodexCliProviderTest();
        $suite->test_health_unavailable_when_binary_missing();
        $suite->test_run_uses_exec_sandbox_and_prompt_argv();
        $suite->test_json_args_remain_configurable();
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

    private function registry(?string $cursorBinary = null): ConfigProviderRegistry
    {
        $cursorOptions = ['simulate' => false, 'useRepoCwd' => false];
        $claudeOptions = ['simulate' => false, 'useRepoCwd' => false, 'promptViaStdin' => false, 'args' => ['-p']];
        $codexOptions = [
            'simulate' => false,
            'useRepoCwd' => false,
            'promptViaStdin' => false,
            'args' => ['exec', '--sandbox', 'workspace-write'],
        ];
        if (is_string($cursorBinary) && $cursorBinary !== '') {
            $cursorOptions['binary'] = $cursorBinary;
            $claudeOptions['binary'] = $cursorBinary;
            $codexOptions['binary'] = $cursorBinary;
        } else {
            $cursorOptions['binary'] = '/tmp/aep-cursor-missing-' . bin2hex(random_bytes(3));
            $claudeOptions['binary'] = '/tmp/aep-claude-missing-' . bin2hex(random_bytes(3));
            $codexOptions['binary'] = '/tmp/aep-codex-missing-' . bin2hex(random_bytes(3));
        }

        return new ConfigProviderRegistry([
            'providers' => [
                ['id' => 'local-agent', 'type' => 'local-agent', 'enabled' => true],
                ['id' => 'cursor', 'type' => 'cursor-cli', 'enabled' => true, 'displayName' => 'Cursor Agent', 'options' => $cursorOptions],
                ['id' => 'codex', 'type' => 'codex-cli', 'enabled' => true, 'displayName' => 'OpenAI Codex', 'options' => $codexOptions],
                ['id' => 'claude-code', 'type' => 'claude-code-cli', 'enabled' => true, 'displayName' => 'Claude Code', 'options' => $claudeOptions],
                ['id' => 'gemini-cli', 'type' => 'stub-cli', 'enabled' => true, 'displayName' => 'Gemini CLI', 'options' => ['simulate' => true]],
            ],
        ], [
            'local-agent' => static fn (array $o): LocalAgentProvider => new LocalAgentProvider($o),
            'stub-cli' => static fn (array $o): StubCliProvider => new StubCliProvider($o),
            'cursor-cli' => static fn (array $o): CursorCliProvider => new CursorCliProvider($o),
            'claude-code-cli' => static fn (array $o): ClaudeCodeCliProvider => new ClaudeCodeCliProvider($o),
            'codex-cli' => static fn (array $o): CodexCliProvider => new CodexCliProvider($o),
            'external-cli' => static fn (array $o): ExternalCliProvider => new ExternalCliProvider($o),
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
