<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\Optimization\Port\OptimizationSettingsStore;
use Aep\Application\Optimization\Port\OptimizationStore;

final class OptimizationQueryService
{
    public function __construct(
        private readonly OptimizationStore $store,
        private readonly OptimizationSettingsStore $settings,
        private readonly OptimizationEngine $engine,
        private readonly ResourceManager $resources,
        private readonly CapacityManager $capacity,
        private readonly BudgetManager $budgets,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array { return $this->settings->get(); }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array { return $this->settings->put($settings); }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $cap = $this->capacity->snapshot();
        $bud = $this->budgets->snapshot();
        $metrics = $this->store->metrics();
        $forecast = $this->engine->forecast();

        return [
            'enabled' => ($this->settings->get()['enabled'] ?? true) === true,
            'mode' => $this->settings->get()['mode'] ?? 'balanced',
            'providerCount' => count($cap['providers']),
            'activeReservations' => count($cap['reservations']),
            'dailyRemaining' => $bud['daily']['remaining'] ?? 0,
            'monthlyRemaining' => $bud['monthly']['remaining'] ?? 0,
            'admitRate' => $metrics['admitRate'] ?? 0,
            'forecastStatus' => $forecast['status'] ?? 'unknown',
            'metrics' => $metrics,
            'capacity' => $cap,
            'budgets' => $bud,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function resources(): array { return $this->resources->list(); }

    /** @return array<string, mixed> */
    public function capacity(): array { return $this->capacity->snapshot(); }

    /** @return array<string, mixed> */
    public function budgets(): array { return $this->budgets->snapshot(); }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateBudgets(array $input): array { return $this->budgets->update($input); }

    /** @return list<array<string, mixed>> */
    public function costs(int $limit = 100): array
    {
        return array_map(static fn ($c) => $c->toArray(), $this->store->listCosts($limit));
    }

    /** @return array<string, mixed> */
    public function forecast(): array { return $this->engine->forecast(); }

    /** @return list<array<string, mixed>> */
    public function providerRanking(): array
    {
        $decision = $this->engine->decide(['mode' => $this->settings->get()['mode'] ?? 'balanced'], false);
        $items = [];
        foreach ($this->store->listProviderCapacity() as $p) {
            $model = $this->store->findCostModel($p->providerId());
            $items[] = $p->toArray() + [
                'estimatedCost' => $model?->estimate() ?? 0.01,
                'selected' => $p->providerId() === $decision->selectedProviderId(),
                'score' => $p->providerId() === $decision->selectedProviderId() ? $decision->score() : null,
            ];
        }
        usort($items, static function (array $a, array $b): int {
            return (($b['selected'] ?? false) <=> ($a['selected'] ?? false)) ?: strcmp((string) $a['providerId'], (string) $b['providerId']);
        });

        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function agentRanking(): array
    {
        return array_map(static fn ($a) => $a->toArray(), $this->store->listAgentCapacity());
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public function decide(array $intent, bool $reserve = false): array
    {
        return $this->engine->decide($intent, $reserve)->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function decisions(int $limit = 50): array
    {
        return array_map(static fn ($d) => $d->toArray(), $this->store->listDecisions($limit));
    }

    /** @return list<array<string, mixed>> */
    public function timeline(int $limit = 100): array
    {
        return array_map(static fn ($e) => $e->toArray(), $this->store->events($limit));
    }

    /** @return array<string, mixed> */
    public function metrics(): array { return $this->store->metrics(); }

    public function engine(): OptimizationEngine { return $this->engine; }
    public function capacityManager(): CapacityManager { return $this->capacity; }

    /** @return array{spentCostUnits: float, workspacesInUse: int} */
    public function planningCapacityFeed(): array
    {
        $bud = $this->budgets->snapshot();
        $ws = $this->store->workspaceCapacity()->toArray();
        $spent = (float) ($bud['daily']['spent'] ?? 0) + (float) ($bud['daily']['reserved'] ?? 0);

        return [
            'spentCostUnits' => $spent,
            'workspacesInUse' => (int) ($ws['inUse'] ?? 0),
        ];
    }
}
