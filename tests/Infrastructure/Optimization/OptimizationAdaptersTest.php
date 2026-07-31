<?php
declare(strict_types=1);
namespace Tests\Infrastructure\Optimization;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;
use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Service\BudgetManager;
use Aep\Application\Optimization\Service\CapacityManager;
use Aep\Application\Optimization\Service\CapacityPlanner;
use Aep\Application\Optimization\Service\OptimizationEngine;
use Aep\Application\Optimization\Service\ResourceAllocator;
use Aep\Application\Optimization\Service\ResourceManager;
use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;
use Aep\Infrastructure\Optimization\Adapter\OptimizationPlanningAdapter;
use Aep\Infrastructure\Optimization\Adapter\OptimizationProviderAdapter;
use Aep\Infrastructure\Optimization\Store\FilesystemOptimizationStore;
use Aep\Infrastructure\Optimization\Store\JsonOptimizationSettingsStore;
use Tests\Support\Assert;

final class OptimizationAdaptersTest
{
    public function test_planning_adapter_passthrough_preserves_launch_shape(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_adapt_' . bin2hex(random_bytes(4));
        try {
            $engine = $this->engine($root);
            $settings = new JsonOptimizationSettingsStore($root);
            $inner = new class implements PlanningLaunchPort {
                public function launchNode(Program $program, ProgramNode $node, string $actorId): array
                {
                    return ['missionId' => 'msn_opt_1', 'runId' => 'run_opt_1', 'message' => 'ok'];
                }
            };
            $adapter = new OptimizationPlanningAdapter($inner, $engine, $settings);
            $node = new ProgramNode('n1', 'Implement feature', 'implement php module changes');
            $program = new Program('prg_1', 'P', 'implement php module changes', Program::STATUS_RUNNING, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', new MissionGraph([$node], []));
            $result = $adapter->launchNode($program, $node, 'tester');
            Assert::same('msn_opt_1', $result['missionId']);
            Assert::same('run_opt_1', $result['runId']);
            Assert::same('ok', $result['message']);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_provider_adapter_injects_provider_id(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_adapt_' . bin2hex(random_bytes(4));
        try {
            $engine = $this->engine($root);
            $settings = new JsonOptimizationSettingsStore($root);
            $inner = new class implements Executor {
                public function id(): string { return 'capture'; }
                public function execute(ExecutionRequest $request): ExecutionResult
                {
                    return ExecutionResult::succeeded('capture', 'ok', [
                        'providerId' => $request->contextValue('providerId'),
                        'routedProviderId' => $request->contextValue('providerId'),
                        'usage' => ['costUsd' => 0.02],
                    ]);
                }
            };
            $adapter = new OptimizationProviderAdapter($inner, $engine, $settings);
            $result = $adapter->execute(new ExecutionRequest('msn_x', 'implement', '2026-01-01T00:00:00Z', []));
            Assert::true($result->isSucceeded());
            Assert::true(is_string($result->context()['providerId'] ?? null));
            Assert::true(count((new FilesystemOptimizationStore($root))->listCosts()) >= 1);
        } finally {
            $this->removeDir($root);
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
        return new OptimizationEngine($store, $settings, $budgets, $capacity, new CapacityPlanner($store), new ResourceAllocator($capacity, $budgets));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
