<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\Optimization\Model\CostModel;
use Aep\Application\Optimization\Model\ExecutionCost;
use Aep\Application\Optimization\Model\OptimizationDecision;
use Aep\Application\Optimization\Model\OptimizationEvent;
use Aep\Application\Optimization\Policy\OptimizationPolicyFactory;
use Aep\Application\Optimization\Port\OptimizationSettingsStore;
use Aep\Application\Optimization\Port\OptimizationStore;

final class OptimizationEngine
{
    public function __construct(
        private readonly OptimizationStore $store,
        private readonly OptimizationSettingsStore $settings,
        private readonly BudgetManager $budgets,
        private readonly CapacityManager $capacity,
        private readonly CapacityPlanner $planner,
        private readonly ResourceAllocator $allocator,
    ) {}

    /**
     * @param array<string, mixed> $intent
     */
    public function decide(array $intent, bool $reserve = false): OptimizationDecision
    {
        $settings = $this->settings->get();
        $at = Utc::now();
        $mode = is_string($intent['mode'] ?? null) ? $intent['mode'] : (is_string($settings['mode'] ?? null) ? $settings['mode'] : 'balanced');
        if (($settings['enabled'] ?? true) !== true) {
            $decision = new OptimizationDecision(
                OptimizationDecision::makeId(), true, $mode, 0.0, $at,
                is_string($intent['preferredProviderId'] ?? null) ? $intent['preferredProviderId'] : 'local-agent',
                [], null, [], null, [], [], 0.0,
                is_string($intent['programId'] ?? null) ? $intent['programId'] : null,
                is_string($intent['nodeId'] ?? null) ? $intent['nodeId'] : null,
                is_string($intent['missionId'] ?? null) ? $intent['missionId'] : null,
                'optimization disabled — passthrough',
            );
            $this->store->saveDecision($decision);
            return $decision;
        }

        $policies = OptimizationPolicyFactory::fromSettings(array_merge($settings, ['mode' => $mode]));
        $projection = $this->planner->project($intent);
        $providers = $this->store->listProviderCapacity();
        $scored = [];
        $rejects = [];

        foreach ($providers as $provider) {
            $model = $this->store->findCostModel($provider->providerId()) ?? new CostModel($provider->providerId());
            $estimated = $model->estimate(
                is_numeric($intent['tokens'] ?? null) ? (float) $intent['tokens'] : 2000.0,
                is_numeric($intent['minutes'] ?? null) ? (float) $intent['minutes'] : 1.0,
            );
            $budget = $this->budgets->check($estimated, $provider->providerId());
            $candidate = $provider->toArray() + [
                'estimatedCost' => $estimated,
                'routable' => $provider->isRoutable() && ($budget['ok'] || ($settings['failOpen'] ?? true) === true),
                'recentWins' => 0,
            ];
            // failOpen still scores but budgetOk false for hard modes
            $hardBudget = ($settings['hardBudgetGate'] ?? false) === true;
            $context = [
                'budgetOk' => $budget['ok'] || !$hardBudget,
                'projection' => $projection,
            ];
            $admit = true;
            $score = 0.0;
            $trace = [];
            foreach ($policies->all() as $policy) {
                $result = $policy->evaluate($intent, $candidate, $context);
                $passed = ($result['admit'] ?? true) === true;
                $delta = is_numeric($result['scoreDelta'] ?? null) ? (float) $result['scoreDelta'] : 0.0;
                $trace[] = ['policy' => $policy->id(), 'admit' => $passed, 'scoreDelta' => $delta, 'reason' => $result['reason'] ?? ''];
                if (!$passed) { $admit = false; }
                $score += $delta;
            }
            if ($admit) {
                $scored[] = ['provider' => $provider, 'score' => $score, 'trace' => $trace, 'estimated' => $estimated, 'budget' => $budget];
            } else {
                $rejects[] = $provider->providerId();
                $this->store->appendEvent(new OptimizationEvent(
                    OptimizationEvent::makeId(), OptimizationEvent::PROVIDER_REJECTED, $at,
                    ['trace' => $trace], null, $provider->providerId(),
                    is_string($intent['programId'] ?? null) ? $intent['programId'] : null,
                    is_string($intent['missionId'] ?? null) ? $intent['missionId'] : null,
                ));
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $winner = $scored[0] ?? null;
        $agents = $this->store->listAgentCapacity();
        $preferredAgent = null;
        $fallbackAgents = [];
        $role = is_string($intent['role'] ?? null) ? $intent['role'] : null;
        foreach ($agents as $agent) {
            if (!$agent->isRoutable()) { continue; }
            if ($role !== null && $role !== '' && $agent->role() !== $role) { continue; }
            if ($preferredAgent === null) { $preferredAgent = $agent->agentId(); }
            else { $fallbackAgents[] = $agent->agentId(); }
        }

        $delay = null;
        if ($winner === null && ($projection['advice'] ?? '') === 'delay') {
            $delay = gmdate('Y-m-d\TH:i:s\Z', time() + 30);
        }

        $fallbacks = [];
        foreach (array_slice($scored, 1, 3) as $row) {
            $fallbacks[] = $row['provider']->providerId();
        }

        $admit = $winner !== null;
        if (!$admit && ($settings['failOpen'] ?? true) === true) {
            $admit = true;
            $selected = is_string($intent['preferredProviderId'] ?? null) ? $intent['preferredProviderId'] : 'local-agent';
            $estimated = 0.01;
            $score = 0.0;
            $trace = [];
            $reason = 'fail-open default provider';
        } else {
            $selected = $winner !== null ? $winner['provider']->providerId() : null;
            $estimated = $winner['estimated'] ?? 0.0;
            $score = $winner['score'] ?? 0.0;
            $trace = $winner['trace'] ?? [];
            $reason = $admit ? 'selected by policy' : 'no admissible provider';
        }

        if ($admit && $delay === null && ($projection['advice'] ?? '') === 'delay' && ($settings['delayOnContention'] ?? false) === true) {
            $delay = gmdate('Y-m-d\TH:i:s\Z', time() + 30);
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(), OptimizationEvent::EXECUTION_DELAYED, $at,
                ['delayUntilUtc' => $delay], null, $selected
            ));
        }

        $decision = new OptimizationDecision(
            OptimizationDecision::makeId(),
            $admit && $delay === null,
            $mode,
            $score,
            $at,
            $selected,
            $fallbacks,
            $preferredAgent,
            $fallbackAgents,
            $delay,
            [],
            $trace,
            $estimated,
            is_string($intent['programId'] ?? null) ? $intent['programId'] : null,
            is_string($intent['nodeId'] ?? null) ? $intent['nodeId'] : null,
            is_string($intent['missionId'] ?? null) ? $intent['missionId'] : null,
            $reason,
            ['rejects' => $rejects, 'projection' => $projection],
        );

        if ($reserve && $decision->admit() && is_string($selected)) {
            $owner = is_string($intent['missionId'] ?? null) ? $intent['missionId'] : ($intent['nodeId'] ?? 'unknown');
            $ids = $this->allocator->bind($decision, is_string($owner) ? $owner : 'unknown');
            $payload = $decision->toArray();
            $payload['reservationIds'] = $ids;
            $decision = OptimizationDecision::fromArray($payload);
        }

        $this->store->saveDecision($decision);
        $this->store->appendEvent(new OptimizationEvent(
            OptimizationEvent::makeId(), OptimizationEvent::OPTIMIZATION_DECISION, $at,
            $decision->toArray(), $decision->decisionId(), $selected,
            $decision->toArray()['programId'], $decision->toArray()['missionId']
        ));
        if (is_string($selected)) {
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(), OptimizationEvent::PROVIDER_SELECTED, $at,
                ['score' => $score], $decision->decisionId(), $selected
            ));
        }
        if (is_string($preferredAgent)) {
            $this->store->appendEvent(new OptimizationEvent(
                OptimizationEvent::makeId(), OptimizationEvent::AGENT_PREFERRED, $at,
                ['agentId' => $preferredAgent], $decision->decisionId()
            ));
        }

        $this->touchMetrics($admit);

        return $decision;
    }

    public function recordActualCost(string $providerId, float $actual, ?string $missionId = null, float $estimated = 0.0): ExecutionCost
    {
        $at = Utc::now();
        $cost = new ExecutionCost(ExecutionCost::makeId(), $providerId, $estimated, $actual, $at, $missionId);
        $this->store->saveExecutionCost($cost);
        $this->budgets->commit($actual, $providerId, $estimated);
        $this->store->appendEvent(new OptimizationEvent(
            OptimizationEvent::makeId(), OptimizationEvent::ACTUAL_COST_RECORDED, $at,
            $cost->toArray(), null, $providerId, null, $missionId
        ));
        $this->updateForecast();

        return $cost;
    }

    /** @return array<string, mixed> */
    public function forecast(): array
    {
        $costs = $this->store->listCosts(50);
        $est = 0.0; $act = 0.0; $n = 0;
        foreach ($costs as $c) {
            $est += $c->estimated();
            $act += $c->actual();
            $n++;
        }
        $ratio = $est > 0 ? $act / $est : 1.0;
        $budgets = $this->budgets->snapshot();
        $dailyRemaining = (float) ($budgets['daily']['remaining'] ?? 0);
        $projectedBurn = $act;

        return [
            'sampleSize' => $n,
            'estimateActualRatio' => $ratio,
            'recentEstimated' => $est,
            'recentActual' => $act,
            'dailyRemaining' => $dailyRemaining,
            'projectedDailyBurn' => $projectedBurn,
            'status' => $dailyRemaining <= 0 ? 'exhausted' : ($ratio > 1.5 ? 'overrunning' : 'healthy'),
        ];
    }

    private function updateForecast(): void
    {
        $forecast = $this->forecast();
        $this->store->appendEvent(new OptimizationEvent(
            OptimizationEvent::makeId(), OptimizationEvent::COST_FORECAST_UPDATED, Utc::now(), $forecast
        ));
    }

    private function touchMetrics(bool $admit): void
    {
        $m = $this->store->metrics();
        $m['decisions'] = (int) ($m['decisions'] ?? 0) + 1;
        $m['admits'] = (int) ($m['admits'] ?? 0) + ($admit ? 1 : 0);
        $m['rejects'] = (int) ($m['rejects'] ?? 0) + ($admit ? 0 : 1);
        $m['admitRate'] = ($m['decisions'] > 0) ? ($m['admits'] / $m['decisions']) : 0.0;
        $m['updatedAtUtc'] = Utc::now();
        $this->store->saveMetrics($m);
    }
}
