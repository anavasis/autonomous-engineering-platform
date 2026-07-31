<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Port;

use Aep\Application\Optimization\Model\AgentCapacity;
use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Model\DailyBudget;
use Aep\Application\Optimization\Model\ExecutionCost;
use Aep\Application\Optimization\Model\ExecutionQuota;
use Aep\Application\Optimization\Model\MonthlyBudget;
use Aep\Application\Optimization\Model\OptimizationDecision;
use Aep\Application\Optimization\Model\OptimizationEvent;
use Aep\Application\Optimization\Model\ProviderCapacity;
use Aep\Application\Optimization\Model\ProviderQuota;
use Aep\Application\Optimization\Model\Resource;
use Aep\Application\Optimization\Model\ResourceAllocation;
use Aep\Application\Optimization\Model\WorkspaceCapacity;

interface OptimizationStore
{
    public function saveResource(Resource $resource): void;
    /** @return list<Resource> */
    public function listResources(): array;
    public function findResource(string $resourceId): ?Resource;

    public function saveProviderCapacity(ProviderCapacity $capacity): void;
    /** @return list<ProviderCapacity> */
    public function listProviderCapacity(): array;
    public function findProviderCapacity(string $providerId): ?ProviderCapacity;

    public function saveAgentCapacity(AgentCapacity $capacity): void;
    /** @return list<AgentCapacity> */
    public function listAgentCapacity(): array;

    public function saveWorkspaceCapacity(WorkspaceCapacity $capacity): void;
    public function workspaceCapacity(): WorkspaceCapacity;

    public function saveCostModel(CostModel $model): void;
    public function findCostModel(string $providerId): ?CostModel;
    /** @return list<CostModel> */
    public function listCostModels(): array;

    public function saveDailyBudget(DailyBudget $budget): void;
    public function dailyBudget(string $dayKey): DailyBudget;
    public function saveMonthlyBudget(MonthlyBudget $budget): void;
    public function monthlyBudget(string $monthKey): MonthlyBudget;

    public function saveExecutionQuota(ExecutionQuota $quota): void;
    public function executionQuota(string $periodKey): ExecutionQuota;
    public function saveProviderQuota(ProviderQuota $quota): void;
    public function providerQuota(string $providerId, string $periodKey): ProviderQuota;

    public function saveAllocation(ResourceAllocation $allocation): void;
    public function findAllocation(string $reservationId): ?ResourceAllocation;
    /** @return list<ResourceAllocation> */
    public function listAllocations(?string $status = null): array;

    public function saveDecision(OptimizationDecision $decision): void;
    public function findDecision(string $decisionId): ?OptimizationDecision;
    /** @return list<OptimizationDecision> */
    public function listDecisions(int $limit = 50): array;

    public function saveExecutionCost(ExecutionCost $cost): void;
    /** @return list<ExecutionCost> */
    public function listCosts(int $limit = 100): array;

    public function appendEvent(OptimizationEvent $event): void;
    /** @return list<OptimizationEvent> */
    public function events(int $limit = 200): array;

    /** @param array<string, mixed> $metrics */
    public function saveMetrics(array $metrics): void;
    /** @return array<string, mixed> */
    public function metrics(): array;
}
