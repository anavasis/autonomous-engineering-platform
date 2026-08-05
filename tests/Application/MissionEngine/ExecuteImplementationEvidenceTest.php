<?php

declare(strict_types=1);

namespace Tests\Application\MissionEngine;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\CancellationToken;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\Step\ExecuteImplementationStep;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\Execution\ProviderRoutingExecutor;
use Aep\Infrastructure\Persistence\InMemoryMissionRepository;
use Tests\Support\Assert;

final class ExecuteImplementationEvidenceTest
{
    public function test_real_success_with_full_evidence_succeeds(): void
    {
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $this->fullEvidence()));
        Assert::true($result->isSucceeded());
        $ctx = $result->context();
        Assert::same('codex', $ctx['providerId'] ?? null);
        Assert::same('esess_1', $ctx['sessionId'] ?? null);
        Assert::same(['README.md'], $ctx['filesChanged'] ?? null);
        Assert::same('patch_1', $ctx['patchId'] ?? null);
        Assert::true(!isset($ctx['timeline']));
        Assert::true(!isset($ctx['prompt']));
    }

    public function test_legacy_bypass_success_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['legacyBypass'] = true;
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'legacyBypass'));
    }

    public function test_declarative_local_success_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['actualExecutorId'] = DeclarativeLocalExecutor::ID;
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'declarative_local'));
    }

    public function test_empty_files_changed_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['filesChanged'] = [];
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'filesChanged'));
    }

    public function test_missing_workspace_path_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        unset($evidence['workspacePath']);
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'workspacePath'));
    }

    public function test_missing_session_id_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        unset($evidence['sessionId']);
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'sessionId'));
    }

    public function test_missing_patch_id_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        unset($evidence['patchId']);
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'patchId'));
    }

    public function test_missing_artifacts_is_rejected(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['artifacts'] = [];
        $result = $this->runWith(ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok', $evidence));
        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'artifacts'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fullEvidence(): array
    {
        return [
            'providerId' => 'codex',
            'routedProviderId' => 'codex',
            'actualExecutorId' => 'provider_routing',
            'sessionId' => 'esess_1',
            'workspacePath' => '/tmp/ws',
            'filesChanged' => ['README.md'],
            'artifacts' => ['diff' => 'art_diff_1'],
            'usage' => ['durationSeconds' => 1.2],
            'checkpointId' => 'cp_1',
            'patchId' => 'patch_1',
            'patchStatus' => 'ready',
            'mergeReady' => true,
        ];
    }

    private function runWith(ExecutionResult $executionResult): \Aep\Application\MissionEngine\StepResult
    {
        $executor = new class ($executionResult) implements Executor {
            public function __construct(private ExecutionResult $result)
            {
            }

            public function id(): string
            {
                return $this->result->executorId();
            }

            public function execute(ExecutionRequest $request): ExecutionResult
            {
                return $this->result;
            }
        };

        $missions = new MissionCommandService(new InMemoryMissionRepository());
        $missions->create(new CreateMission(
            'msn_ev_1',
            'github',
            'anavasis/aep-codex-smoke',
            'smoke',
            'user',
            'tester',
            '2026-08-05T12:00:00Z'
        ));
        $missions->defineScope(new DefineScope('msn_ev_1', ['README.md'], []));

        return (new ExecuteImplementationStep())->execute(new MissionContext(
            'run_ev_1',
            'msn_ev_1',
            '2026-08-05T12:00:00Z',
            'user',
            'tester',
            $missions,
            new CancellationToken(),
            ['providerId' => 'codex', 'allowedPaths' => ['README.md']],
            null,
            new ExecutionService($executor),
        ));
    }
}
