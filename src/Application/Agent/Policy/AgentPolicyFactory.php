<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Policy;

final class AgentPolicyFactory
{
    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): AgentPolicySet
    {
        $weights = is_array($settings['selectionWeights'] ?? null) ? $settings['selectionWeights'] : [];
        return new AgentPolicySet([
            new PinAgentPolicy(),
            new RequireAvailablePolicy(),
            new RequireHealthyPolicy(),
            new RoleMatchPolicy(),
            new CapabilityMatchPolicy(),
            new ScoreBySuccessRatePolicy(is_numeric($weights['success'] ?? null) ? (float) $weights['success'] : 10.0),
            new LowestCostPolicy(is_numeric($weights['cost'] ?? null) ? (float) $weights['cost'] : 5.0),
            new HighestConfidencePolicy(is_numeric($weights['confidence'] ?? null) ? (float) $weights['confidence'] : 8.0),
            new LoadBalancingPolicy(),
        ]);
    }
}
