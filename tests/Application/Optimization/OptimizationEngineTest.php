<?php
declare(strict_types=1);
namespace Tests\Application\Optimization;

use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Model\OptimizationEvent;
use Aep\Application\Optimization\Service\BudgetManager;
use Aep\Application\Optimization\Service\CapacityManager;
use Aep\Application\Optimization\Service\CapacityPlanner;
use Aep\Application\Optimization\Service\OptimizationEngine;
use Aep\Application\Optimization\Service\ResourceAllocator;
use Aep\Application\Optimization\Service\ResourceManager;
use Aep\Infrastructure\Optimization\Store\FilesystemOptimizationStore;
use Aep\Infrastructure\Optimization\Store\JsonOptimizationSettingsStore;
use Tests\Support\Assert;

final class OptimizationEngineTest
{
    public function test_decide_selects_provider_with_policy_trace(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_' . bin2hex(random_bytes(4));
        try {
            $engine = $this->engine($root);
            $decision = $engine->decide(['priority' => 'high', 'tokens' => 1000], false);
            Assert::true($decision->admit());
            Assert::true(is_string($decision->selectedProviderId()) && $decision->selectedProviderId() !== '');
            Assert::true(str_starts_with($decision->decisionId(), 'opt_'));
            Assert::true(count($decision->toArray()['policyTrace']) >= 1);
            $types = array_map(static fn ($e) => $e->type(), $this->store($root)->events());
            Assert::true(in_array(OptimizationEvent::OPTIMIZATION_DECISION, $types, true));
            Assert::true(in_array(OptimizationEvent::PROVIDER_SELECTED, $types, true));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_hard_budget_gate_can_reject(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_' . bin2hex(random_bytes(4));
        try {
            $settings = new JsonOptimizationSettingsStore($root);
            $settings->put([
                'hardBudgetGate' => true,
                'failOpen' => false,
                'dailyBudgetLimit' => 0.000001,
            ]);
            $store = new FilesystemOptimizationStore($root);
            $store->saveCostModel(new CostModel('local-agent', 1.0, 1.0, 1.0, 1.0));
            $capacity = new CapacityManager($store, $settings);
            $capacity->seedFromConfig(['providers' => [['id' => 'local-agent', 'maxSessions' => 2]]]);
            $budgets = new BudgetManager($store, $settings);
            $budgets->ensureDefaults();
            // spend the tiny budget
            $budgets->commit(1.0);
            $engine = new OptimizationEngine(
                $store, $settings, $budgets, $capacity, new CapacityPlanner($store),
                new ResourceAllocator($capacity, $budgets)
            );
            $decision = $engine->decide(['preferredProviderId' => 'local-agent'], false);
            Assert::true($decision->admit() === false || $decision->selectedProviderId() === null || $decision->reason() !== '');
            // With hard gate and failOpen false and no admissible provider:
            Assert::same(false, $decision->admit());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_pin_provider_and_record_actual_cost(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_' . bin2hex(random_bytes(4));
        try {
            $engine = $this->engine($root);
            $decision = $engine->decide(['preferredProviderId' => 'stub-cli'], true);
            Assert::same('stub-cli', $decision->selectedProviderId());
            $cost = $engine->recordActualCost('stub-cli', 0.05, 'msn_1', 0.04);
            Assert::true(str_starts_with($cost->costId(), 'cost_'));
            Assert::same(0.05, $cost->actual());
            $forecast = $engine->forecast();
            Assert::true(isset($forecast['estimateActualRatio']));
        } finally {
            $this->removeDir($root);
        }
    }

    private function engine(string $root): OptimizationEngine
    {
        $settings = new JsonOptimizationSettingsStore($root);
        $store = new FilesystemOptimizationStore($root);
        $config = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/deploy/optimization.json'), true);
        Assert::true(is_array($config));
        (new ResourceManager($store))->seedFromConfig($config);
        $capacity = new CapacityManager($store, $settings);
        $capacity->seedFromConfig($config);
        foreach ($config['costModels'] as $cm) {
            $store->saveCostModel(CostModel::fromArray($cm));
        }
        $budgets = new BudgetManager($store, $settings);
        $budgets->ensureDefaults();
        return new OptimizationEngine(
            $store, $settings, $budgets, $capacity, new CapacityPlanner($store),
            new ResourceAllocator($capacity, $budgets)
        );
    }

    private function store(string $root): FilesystemOptimizationStore
    {
        return new FilesystemOptimizationStore($root);
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
