<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;

final class OptimizationPolicyFactory
{
    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): OptimizationPolicySet
    {
        $mode = is_string($settings['mode'] ?? null) ? $settings['mode'] : 'balanced';
        $weights = is_array($settings['weights'] ?? null) ? $settings['weights'] : [];
        $costW = is_numeric($weights['cost'] ?? null) ? (float) $weights['cost'] : 10.0;
        $qualityW = is_numeric($weights['quality'] ?? null) ? (float) $weights['quality'] : 12.0;
        $latencyW = is_numeric($weights['latency'] ?? null) ? (float) $weights['latency'] : 8.0;

        if ($mode === 'lowest_cost') { $costW *= 2; $qualityW *= 0.5; }
        if ($mode === 'highest_quality') { $qualityW *= 2; $costW *= 0.5; }
        if ($mode === 'fastest') { $latencyW *= 2; }
        if ($mode === 'emergency') { $costW *= 0.2; $qualityW *= 0.5; $latencyW *= 1.5; }

        return new OptimizationPolicySet([
            new PinProviderPolicy(),
            new RequireBudgetPolicy(),
            new RequireCapacityPolicy(),
            new ProviderHealthPolicy(),
            new LowestCostPolicy($costW),
            new HighestQualityPolicy($qualityW),
            new FastestProviderPolicy($latencyW),
            new AvoidStarvationPolicy(),
            new PriorityMissionPolicy(),
            new EcoModePolicy($mode === 'eco'),
        ]);
    }
}
