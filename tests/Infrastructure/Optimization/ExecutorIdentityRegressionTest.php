<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Optimization;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Application\MissionControl\Auth\Role;
use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Service\BudgetManager;
use Aep\Application\Optimization\Service\CapacityManager;
use Aep\Application\Optimization\Service\CapacityPlanner;
use Aep\Application\Optimization\Service\OptimizationEngine;
use Aep\Application\Optimization\Service\ResourceAllocator;
use Aep\Application\Optimization\Service\ResourceManager;
use Aep\Application\Project\Command\BindRepository;
use Aep\Application\Project\ProjectCommandService;
use Aep\Infrastructure\Execution\ProviderRoutingExecutor;
use Aep\Infrastructure\MissionControl\MissionControlKernel;
use Aep\Infrastructure\Optimization\Adapter\OptimizationProviderAdapter;
use Aep\Infrastructure\Optimization\Store\FilesystemOptimizationStore;
use Aep\Infrastructure\Optimization\Store\JsonOptimizationSettingsStore;
use Aep\Infrastructure\Persistence\JsonFileProjectRepository;
use Tests\Support\Assert;

/**
 * Regression: OptimizationProviderAdapter must be a transparent decorator so
 * ExecutionService identity checks pass on Mission start and resume.
 */
final class ExecutorIdentityRegressionTest
{
    public function test_adapter_id_delegates_to_inner_executor(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_id_' . bin2hex(random_bytes(4));
        try {
            $inner = new class implements Executor {
                public function id(): string
                {
                    return ProviderRoutingExecutor::ID;
                }

                public function execute(ExecutionRequest $request): ExecutionResult
                {
                    return ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'ok');
                }
            };
            $adapter = new OptimizationProviderAdapter($inner, $this->engine($root), new JsonOptimizationSettingsStore($root));
            Assert::same(ProviderRoutingExecutor::ID, $adapter->id());
            Assert::notSame(OptimizationProviderAdapter::ID, $adapter->id());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_execution_service_accepts_result_through_transparent_adapter(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_svc_' . bin2hex(random_bytes(4));
        try {
            $inner = new class implements Executor {
                public function id(): string
                {
                    return ProviderRoutingExecutor::ID;
                }

                public function execute(ExecutionRequest $request): ExecutionResult
                {
                    // Simulate ProviderRoutingExecutor: result id matches router id.
                    return ExecutionResult::succeeded(ProviderRoutingExecutor::ID, 'routed', [
                        'routedProviderId' => 'local-agent',
                        'providerId' => $request->contextValue('providerId'),
                    ]);
                }
            };
            $adapter = new OptimizationProviderAdapter($inner, $this->engine($root), new JsonOptimizationSettingsStore($root));
            $service = new ExecutionService($adapter);

            Assert::same(ProviderRoutingExecutor::ID, $service->execute(
                new ExecutionRequest('msn_id', 'implement', '2026-08-03T00:00:00Z', [])
            )->executorId());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_mission_start_inspection_resume_implementation_without_executor_mismatch(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_msn_' . bin2hex(random_bytes(4));
        putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD=exec-id-secret');
        putenv('AEP_RUNTIME_INLINE=false');
        try {
            $kernel = new MissionControlKernel($root, '1.7.3');
            $actor = new User(
                'user_exec_id',
                'operator',
                'Operator',
                password_hash('x', PASSWORD_ARGON2ID),
                new Role(Role::ADMIN),
                '2026-08-03T00:00:00Z',
            );

            $kernel->commands()->createProject($actor, 'proj_exec_id', 'exec-id', 'Exec Id', 'test');
            $projects = new ProjectCommandService(new JsonFileProjectRepository($root . '/projects'));
            $projects->bindRepository(new BindRepository(
                'proj_exec_id',
                'github',
                'local/exec-id',
                '2026-08-03T00:00:00Z',
            ));
            $kernel = new MissionControlKernel($root, '1.7.3');

            // Stage: Mission start via AME → Runtime (same path as resume later).
            $intake = $kernel->ame()->intake(
                $actor,
                "Fix a small bug in src/Example.php.\nInspection first.\ngithub:local/exec-id",
                'proj_exec_id',
                'client_exec_id_1',
            );
            Assert::same(MissionIntake::STATUS_READY, $intake['intake']['status'] ?? null);
            $intakeId = (string) $intake['intake']['id'];
            $kernel->ame()->preview($intakeId);
            $launched = $kernel->ame()->confirmAndLaunch($actor, $intakeId, true);
            Assert::true(isset($launched['missionId'], $launched['runId'], $launched['jobId']));

            Assert::same(1, $kernel->runtimeWorker()->processAvailable('exec-id-w1', 3));
            $missionId = (string) $launched['missionId'];
            $run = $kernel->missions()->latestRun($missionId);
            Assert::true($run !== null);
            Assert::same(MissionRunState::WAITING, $run->engineState());
            Assert::true(str_contains($run->message(), 'inspection'));

            // Stage: Inspection approval → resume enqueued (same ExecutionService stack as start drive).
            $gate = $kernel->commands()->decideGate($actor, $run->runId(), 'inspection', true);
            Assert::true(isset($gate['jobId']));
            Assert::same(1, $kernel->runtimeWorker()->processAvailable('exec-id-w2', 5));

            $after = $kernel->missions()->latestRun($missionId);
            Assert::true($after !== null);
            Assert::notSame(
                MissionRunState::FAILED,
                $after->engineState(),
                'Resume/implementation failed: ' . $after->message()
            );
            Assert::true(
                !str_contains($after->message(), 'mismatched executorId'),
                'executorId mismatch still present: ' . $after->message()
            );

            // ExecuteImplementationStep ran successfully enough to leave waiting/completed/running,
            // not the previous identity failure.
            Assert::true(
                in_array($after->engineState(), [
                    MissionRunState::WAITING,
                    MissionRunState::COMPLETED,
                    MissionRunState::RUNNING,
                    MissionRunState::SUSPENDED,
                ], true),
                'Unexpected engine state after implementation: ' . $after->engineState() . ' — ' . $after->message()
            );
        } finally {
            $this->removeDir($root);
            putenv('AEP_BOOTSTRAP_ADMIN_PASSWORD');
            putenv('AEP_RUNTIME_INLINE');
        }
    }

    private function engine(string $root): OptimizationEngine
    {
        $settings = new JsonOptimizationSettingsStore($root);
        $store = new FilesystemOptimizationStore($root);
        $config = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/deploy/optimization.json'), true);
        (new ResourceManager($store))->seedFromConfig($config);
        $capacity = new CapacityManager($store, $settings);
        $capacity->seedFromConfig($config);
        foreach ($config['costModels'] as $cm) {
            $store->saveCostModel(CostModel::fromArray($cm));
        }
        $budgets = new BudgetManager($store, $settings);
        $budgets->ensureDefaults();

        return new OptimizationEngine(
            $store,
            $settings,
            $budgets,
            $capacity,
            new CapacityPlanner($store),
            new ResourceAllocator($capacity, $budgets)
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
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
