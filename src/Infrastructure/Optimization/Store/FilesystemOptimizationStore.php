<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Optimization\Store;

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
use Aep\Application\Optimization\Port\OptimizationStore;

final class FilesystemOptimizationStore implements OptimizationStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/resources',
            $this->root . '/capacity/providers',
            $this->root . '/capacity/agents',
            $this->root . '/budgets',
            $this->root . '/budgets/providers',
            $this->root . '/cost-models',
            $this->root . '/costs',
            $this->root . '/costs/by-mission',
            $this->root . '/reservations',
            $this->root . '/decisions',
            $this->root . '/events',
            $this->root . '/metrics',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create optimization store: ' . $dir);
            }
        }
        $ws = $this->root . '/capacity/workspaces.json';
        if (!is_file($ws)) {
            file_put_contents($ws, json_encode((new WorkspaceCapacity())->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
        $metrics = $this->root . '/metrics/snapshot.json';
        if (!is_file($metrics)) {
            file_put_contents($metrics, json_encode(['decisions' => 0, 'admits' => 0, 'rejects' => 0, 'admitRate' => 0.0], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create dir: ' . $dir);
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) { return null; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: 'unknown';
    }

    public function saveResource(Resource $resource): void
    {
        $this->writeJson($this->root . '/resources/' . $this->safe($resource->resourceId()) . '.json', $resource->toArray());
    }

    public function listResources(): array
    {
        $out = [];
        foreach (glob($this->root . '/resources/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = Resource::fromArray($data); }
        }
        return $out;
    }

    public function findResource(string $resourceId): ?Resource
    {
        $data = $this->readJson($this->root . '/resources/' . $this->safe($resourceId) . '.json');
        return $data !== null ? Resource::fromArray($data) : null;
    }

    public function saveProviderCapacity(ProviderCapacity $capacity): void
    {
        $this->writeJson($this->root . '/capacity/providers/' . $this->safe($capacity->providerId()) . '.json', $capacity->toArray());
    }

    public function listProviderCapacity(): array
    {
        $out = [];
        foreach (glob($this->root . '/capacity/providers/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = ProviderCapacity::fromArray($data); }
        }
        return $out;
    }

    public function findProviderCapacity(string $providerId): ?ProviderCapacity
    {
        $data = $this->readJson($this->root . '/capacity/providers/' . $this->safe($providerId) . '.json');
        return $data !== null ? ProviderCapacity::fromArray($data) : null;
    }

    public function saveAgentCapacity(AgentCapacity $capacity): void
    {
        $this->writeJson($this->root . '/capacity/agents/' . $this->safe($capacity->agentId()) . '.json', $capacity->toArray());
    }

    public function listAgentCapacity(): array
    {
        $out = [];
        foreach (glob($this->root . '/capacity/agents/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = AgentCapacity::fromArray($data); }
        }
        return $out;
    }

    public function saveWorkspaceCapacity(WorkspaceCapacity $capacity): void
    {
        $this->writeJson($this->root . '/capacity/workspaces.json', $capacity->toArray());
    }

    public function workspaceCapacity(): WorkspaceCapacity
    {
        $data = $this->readJson($this->root . '/capacity/workspaces.json');
        return $data !== null ? WorkspaceCapacity::fromArray($data) : new WorkspaceCapacity();
    }

    public function saveCostModel(CostModel $model): void
    {
        $this->writeJson($this->root . '/cost-models/' . $this->safe($model->providerId()) . '.json', $model->toArray());
    }

    public function findCostModel(string $providerId): ?CostModel
    {
        $data = $this->readJson($this->root . '/cost-models/' . $this->safe($providerId) . '.json');
        return $data !== null ? CostModel::fromArray($data) : null;
    }

    public function listCostModels(): array
    {
        $out = [];
        foreach (glob($this->root . '/cost-models/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = CostModel::fromArray($data); }
        }
        return $out;
    }

    public function saveDailyBudget(DailyBudget $budget): void
    {
        $this->writeJson($this->root . '/budgets/daily-' . $this->safe($budget->dayKey()) . '.json', $budget->toArray());
    }

    public function dailyBudget(string $dayKey): DailyBudget
    {
        $data = $this->readJson($this->root . '/budgets/daily-' . $this->safe($dayKey) . '.json');
        return $data !== null ? DailyBudget::fromArray($data) : new DailyBudget($dayKey, 100.0);
    }

    public function saveMonthlyBudget(MonthlyBudget $budget): void
    {
        $this->writeJson($this->root . '/budgets/monthly-' . $this->safe($budget->monthKey()) . '.json', $budget->toArray());
    }

    public function monthlyBudget(string $monthKey): MonthlyBudget
    {
        $data = $this->readJson($this->root . '/budgets/monthly-' . $this->safe($monthKey) . '.json');
        return $data !== null ? MonthlyBudget::fromArray($data) : new MonthlyBudget($monthKey, 2000.0);
    }

    public function saveExecutionQuota(ExecutionQuota $quota): void
    {
        $this->writeJson($this->root . '/budgets/execution-quota-' . $this->safe($quota->toArray()['periodKey']) . '.json', $quota->toArray());
    }

    public function executionQuota(string $periodKey): ExecutionQuota
    {
        $data = $this->readJson($this->root . '/budgets/execution-quota-' . $this->safe($periodKey) . '.json');
        return $data !== null ? ExecutionQuota::fromArray($data) : new ExecutionQuota($periodKey, 1000);
    }

    public function saveProviderQuota(ProviderQuota $quota): void
    {
        $this->writeJson(
            $this->root . '/budgets/providers/' . $this->safe($quota->providerId()) . '-' . $this->safe($quota->toArray()['periodKey']) . '.json',
            $quota->toArray()
        );
    }

    public function providerQuota(string $providerId, string $periodKey): ProviderQuota
    {
        $data = $this->readJson($this->root . '/budgets/providers/' . $this->safe($providerId) . '-' . $this->safe($periodKey) . '.json');
        return $data !== null ? ProviderQuota::fromArray($data) : new ProviderQuota($providerId, $periodKey, 500, 0, 100.0, 0.0);
    }

    public function saveAllocation(ResourceAllocation $allocation): void
    {
        $this->writeJson($this->root . '/reservations/' . $this->safe($allocation->reservationId()) . '.json', $allocation->toArray());
    }

    public function findAllocation(string $reservationId): ?ResourceAllocation
    {
        $data = $this->readJson($this->root . '/reservations/' . $this->safe($reservationId) . '.json');
        return $data !== null ? ResourceAllocation::fromArray($data) : null;
    }

    public function listAllocations(?string $status = null): array
    {
        $out = [];
        foreach (glob($this->root . '/reservations/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null) { continue; }
            $a = ResourceAllocation::fromArray($data);
            if ($status !== null && $status !== '' && $a->status() !== $status) { continue; }
            $out[] = $a;
        }
        return $out;
    }

    public function saveDecision(OptimizationDecision $decision): void
    {
        $this->writeJson($this->root . '/decisions/' . $this->safe($decision->decisionId()) . '.json', $decision->toArray());
    }

    public function findDecision(string $decisionId): ?OptimizationDecision
    {
        $data = $this->readJson($this->root . '/decisions/' . $this->safe($decisionId) . '.json');
        return $data !== null ? OptimizationDecision::fromArray($data) : null;
    }

    public function listDecisions(int $limit = 50): array
    {
        $files = glob($this->root . '/decisions/*.json') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = OptimizationDecision::fromArray($data); }
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    public function saveExecutionCost(ExecutionCost $cost): void
    {
        $this->writeJson($this->root . '/costs/' . $this->safe($cost->costId()) . '.json', $cost->toArray());
        $line = json_encode($cost->toArray(), JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->root . '/costs/history.jsonl', $line, FILE_APPEND);
        if ($cost->missionId()) {
            $this->writeJson($this->root . '/costs/by-mission/' . $this->safe($cost->missionId()) . '.json', $cost->toArray());
        }
    }

    public function listCosts(int $limit = 100): array
    {
        $path = $this->root . '/costs/history.jsonl';
        if (!is_file($path)) { return []; }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) { $items[] = ExecutionCost::fromArray($data); }
            if (count($items) >= $limit) { break; }
        }
        return $items;
    }

    public function appendEvent(OptimizationEvent $event): void
    {
        file_put_contents(
            $this->root . '/events/timeline.jsonl',
            json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND
        );
    }

    public function events(int $limit = 200): array
    {
        $path = $this->root . '/events/timeline.jsonl';
        if (!is_file($path)) { return []; }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) { $items[] = OptimizationEvent::fromArray($data); }
            if (count($items) >= $limit) { break; }
        }
        return $items;
    }

    public function saveMetrics(array $metrics): void
    {
        $this->writeJson($this->root . '/metrics/snapshot.json', $metrics);
    }

    public function metrics(): array
    {
        return $this->readJson($this->root . '/metrics/snapshot.json') ?? ['decisions' => 0, 'admits' => 0, 'rejects' => 0, 'admitRate' => 0.0];
    }
}
