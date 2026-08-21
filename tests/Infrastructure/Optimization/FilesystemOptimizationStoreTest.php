<?php
declare(strict_types=1);
namespace Tests\Infrastructure\Optimization;

use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Model\DailyBudget;
use Aep\Application\Optimization\Model\ExecutionCost;
use Aep\Application\Optimization\Model\OptimizationDecision;
use Aep\Application\Optimization\Model\OptimizationEvent;
use Aep\Application\Optimization\Model\ProviderCapacity;
use Aep\Application\Optimization\Model\Resource;
use Aep\Application\Optimization\Model\ResourceAllocation;
use Aep\Infrastructure\Optimization\Store\FilesystemOptimizationStore;
use Aep\Infrastructure\Optimization\Store\JsonOptimizationSettingsStore;
use Tests\Support\Assert;

final class FilesystemOptimizationStoreTest
{
    public function test_persist_resources_capacity_budgets_decisions_and_events(): void
    {
        $root = sys_get_temp_dir() . '/aep_opt_store_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemOptimizationStore($root);
            $store->saveResource(new Resource('res_1', 'provider.slot', 'global', 'sessions', ['max' => 4], [], '2026-01-01T00:00:00Z'));
            $store->saveProviderCapacity(new ProviderCapacity('local-agent', 'available', 4));
            $store->saveCostModel(new CostModel('local-agent', 0.0, 0.01));
            $store->saveDailyBudget(new DailyBudget('2026-01-01', 50.0, 1.0, 2.0));
            $store->saveAllocation(new ResourceAllocation('rsv_1', 'provider.slot:local-agent', 1.0, ResourceAllocation::STATUS_RESERVED, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 'mission', 'msn_1'));
            $decision = new OptimizationDecision('opt_1', true, 'balanced', 10.0, '2026-01-01T00:00:00Z', 'local-agent');
            $store->saveDecision($decision);
            $store->saveExecutionCost(new ExecutionCost('cost_1', 'local-agent', 0.02, 0.03, '2026-01-01T00:00:00Z', 'msn_1'));
            $store->appendEvent(new OptimizationEvent('oev_1', OptimizationEvent::OPTIMIZATION_DECISION, '2026-01-01T00:00:00Z', [], 'opt_1', 'local-agent'));

            Assert::same('res_1', $store->findResource('res_1')?->resourceId());
            Assert::same('local-agent', $store->findProviderCapacity('local-agent')?->providerId());
            Assert::same(50.0, $store->dailyBudget('2026-01-01')->limit());
            Assert::same('opt_1', $store->findDecision('opt_1')?->decisionId());
            Assert::true(count($store->listCosts()) >= 1);
            Assert::true(count($store->events()) >= 1);
            $settings = new JsonOptimizationSettingsStore($root);
            Assert::same(true, $settings->get()['resourceCostCapacityOptimization']);
        } finally {
            $this->removeDir($root);
        }
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
