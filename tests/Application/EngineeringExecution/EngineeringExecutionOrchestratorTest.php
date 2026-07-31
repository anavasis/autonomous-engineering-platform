<?php

declare(strict_types=1);

namespace Tests\Application\EngineeringExecution;

use Aep\Application\EngineeringExecution\Service\ArtifactCapture;
use Aep\Application\EngineeringExecution\Service\ContextPackager;
use Aep\Application\EngineeringExecution\Service\DiffCollector;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\EngineeringExecution\Service\PromptPipeline;
use Aep\Application\EngineeringExecution\Service\ResultNormalizer;
use Aep\Application\Execution\ExecutionRequest;
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
            $orchestrator = $this->build($root);
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
            $orchestrator = $this->build($root);
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
            $orchestrator = $this->build($root);
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

    private function build(string $root): EngineeringExecutionOrchestrator
    {
        $executionDir = $root . '/execution';
        if (!is_dir($executionDir)) {
            mkdir($executionDir, 0775, true);
        }
        $factory = EngineeringWorkspaceTestFactory::make($root);
        $registry = new ConfigProviderRegistry([
            'providers' => [
                ['id' => 'local-agent', 'type' => 'local-agent', 'enabled' => true],
            ],
        ], [
            'local-agent' => static fn (array $o): LocalAgentProvider => new LocalAgentProvider($o),
        ]);

        return new EngineeringExecutionOrchestrator(
            $registry,
            new JsonExecutionSessionStore($executionDir),
            new PromptPipeline(),
            new ContextPackager(),
            $factory['preparer'],
            new DiffCollector(),
            new ArtifactCapture($factory['artifacts']),
            new ResultNormalizer(),
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
