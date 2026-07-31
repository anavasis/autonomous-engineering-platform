<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

final class SchedulingPolicyFactory
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): SchedulingPolicySet
    {
        $maxInFlight = is_int($settings['maxInFlight'] ?? null) ? $settings['maxInFlight'] : 3;
        $maxWs = is_int($settings['maxConcurrentWorkspaces'] ?? null) ? $settings['maxConcurrentWorkspaces'] : 32;

        return new SchedulingPolicySet([
            new RequireNodeReadyPolicy(),
            new PriorityOrderPolicy(),
            new ConcurrencyCapPolicy($maxInFlight),
            new ProviderCapacityPolicy(),
            new WorkspaceCapacityPolicy($maxWs),
            new DeadlineUrgencyPolicy(),
            new CostBudgetPolicy(),
        ]);
    }
}
