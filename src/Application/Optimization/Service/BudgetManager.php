<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Optimization\Model\DailyBudget;
use Aep\Application\Optimization\Model\ExecutionQuota;
use Aep\Application\Optimization\Model\MonthlyBudget;
use Aep\Application\Optimization\Model\OptimizationEvent;
use Aep\Application\Optimization\Model\ProviderQuota;
use Aep\Application\Optimization\Port\OptimizationSettingsStore;
use Aep\Application\Optimization\Port\OptimizationStore;

final class BudgetManager
{
    public function __construct(
        private readonly OptimizationStore $store,
        private readonly OptimizationSettingsStore $settings,
    ) {}

    public function ensureDefaults(): void
    {
        $s = $this->settings->get();
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        $daily = $this->store->dailyBudget($day);
        if ($daily->limit() <= 0) {
            $this->store->saveDailyBudget(new DailyBudget($day, (float) ($s['dailyBudgetLimit'] ?? 100.0)));
        }
        $monthly = $this->store->monthlyBudget($month);
        if ($monthly->limit() <= 0) {
            $this->store->saveMonthlyBudget(new MonthlyBudget($month, (float) ($s['monthlyBudgetLimit'] ?? 2000.0)));
        }
        $eq = $this->store->executionQuota($day);
        if ($eq->toArray()['maxRequests'] <= 0) {
            $this->store->saveExecutionQuota(new ExecutionQuota($day, (int) ($s['dailyRequestLimit'] ?? 1000)));
        }
    }

    /** @return array{ok: bool, reason: string, daily: array<string,mixed>, monthly: array<string,mixed>} */
    public function check(float $estimatedCost, ?string $providerId = null): array
    {
        $this->ensureDefaults();
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        $daily = $this->store->dailyBudget($day);
        $monthly = $this->store->monthlyBudget($month);
        $quota = $this->store->executionQuota($day);

        if (!$quota->canAdmit(1)) {
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(), OptimizationEvent::QUOTA_EXCEEDED, Utc::now(),
                ['kind' => 'execution', 'estimatedCost' => $estimatedCost]
            ));
            return ['ok' => false, 'reason' => 'execution quota exceeded', 'daily' => $daily->toArray(), 'monthly' => $monthly->toArray()];
        }
        if (!$daily->canAfford($estimatedCost)) {
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(), OptimizationEvent::BUDGET_EXCEEDED, Utc::now(),
                ['kind' => 'daily', 'estimatedCost' => $estimatedCost]
            ));
            return ['ok' => false, 'reason' => 'daily budget exceeded', 'daily' => $daily->toArray(), 'monthly' => $monthly->toArray()];
        }
        if (!$monthly->canAfford($estimatedCost)) {
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(), OptimizationEvent::BUDGET_EXCEEDED, Utc::now(),
                ['kind' => 'monthly', 'estimatedCost' => $estimatedCost]
            ));
            return ['ok' => false, 'reason' => 'monthly budget exceeded', 'daily' => $daily->toArray(), 'monthly' => $monthly->toArray()];
        }
        if (is_string($providerId) && $providerId !== '') {
            $pq = $this->store->providerQuota($providerId, $day);
            if (!$pq->canAdmit($estimatedCost)) {
                $this->store->appendEvent(new OptimizationEvent(
                    OptimizationEvent::makeId(), OptimizationEvent::QUOTA_EXCEEDED, Utc::now(),
                    ['kind' => 'provider', 'providerId' => $providerId], null, $providerId
                ));
                return ['ok' => false, 'reason' => 'provider quota exceeded', 'daily' => $daily->toArray(), 'monthly' => $monthly->toArray()];
            }
        }

        return ['ok' => true, 'reason' => 'ok', 'daily' => $daily->toArray(), 'monthly' => $monthly->toArray()];
    }

    public function reserve(float $estimatedCost, ?string $providerId = null): void
    {
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        $this->store->saveDailyBudget($this->store->dailyBudget($day)->withReserve($estimatedCost));
        $this->store->saveMonthlyBudget($this->store->monthlyBudget($month)->withReserve($estimatedCost));
        $this->store->saveExecutionQuota($this->store->executionQuota($day)->withUse(1));
        if (is_string($providerId) && $providerId !== '') {
            $this->store->saveProviderQuota($this->store->providerQuota($providerId, $day)->withUse(0.0, 0));
        }
    }

    public function commit(float $actualCost, ?string $providerId = null, float $reserved = 0.0): void
    {
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        $daily = $this->store->dailyBudget($day);
        $monthly = $this->store->monthlyBudget($month);
        if ($reserved > 0) {
            $daily = $daily->withRelease($reserved);
            $monthly = $monthly->withRelease($reserved);
        }
        $this->store->saveDailyBudget(new DailyBudget($day, $daily->limit(), $daily->spent() + $actualCost, $daily->reserved(), $daily->toArray()['scope'] ?? 'global'));
        $this->store->saveMonthlyBudget(new MonthlyBudget($month, $monthly->limit(), $monthly->spent() + $actualCost, $monthly->reserved(), $monthly->toArray()['scope'] ?? 'global'));
        if (is_string($providerId) && $providerId !== '') {
            $this->store->saveProviderQuota($this->store->providerQuota($providerId, $day)->withUse($actualCost, 1));
        }
    }

    public function release(float $estimatedCost): void
    {
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        $this->store->saveDailyBudget($this->store->dailyBudget($day)->withRelease($estimatedCost));
        $this->store->saveMonthlyBudget($this->store->monthlyBudget($month)->withRelease($estimatedCost));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $input): array
    {
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        if (isset($input['dailyLimit']) && is_numeric($input['dailyLimit'])) {
            $cur = $this->store->dailyBudget($day);
            $this->store->saveDailyBudget(new DailyBudget($day, (float) $input['dailyLimit'], $cur->spent(), $cur->reserved()));
        }
        if (isset($input['monthlyLimit']) && is_numeric($input['monthlyLimit'])) {
            $cur = $this->store->monthlyBudget($month);
            $this->store->saveMonthlyBudget(new MonthlyBudget($month, (float) $input['monthlyLimit'], $cur->spent(), $cur->reserved()));
        }
        if (isset($input['providerId'], $input['providerMaxCost']) && is_string($input['providerId']) && is_numeric($input['providerMaxCost'])) {
            $pq = $this->store->providerQuota($input['providerId'], $day);
            $this->store->saveProviderQuota(new ProviderQuota(
                $input['providerId'], $day, $pq->toArray()['maxRequests'], $pq->toArray()['usedRequests'],
                (float) $input['providerMaxCost'], $pq->toArray()['spentCost']
            ));
        }

        return $this->snapshot();
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $this->ensureDefaults();
        $day = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        $providerQuotas = [];
        foreach ($this->store->listProviderCapacity() as $p) {
            $providerQuotas[] = $this->store->providerQuota($p->providerId(), $day)->toArray();
        }

        return [
            'daily' => $this->store->dailyBudget($day)->toArray(),
            'monthly' => $this->store->monthlyBudget($month)->toArray(),
            'executionQuota' => $this->store->executionQuota($day)->toArray(),
            'providerQuotas' => $providerQuotas,
        ];
    }
}
