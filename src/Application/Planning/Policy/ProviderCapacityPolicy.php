<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;

final class ProviderCapacityPolicy implements SchedulingPolicy
{
    public function id(): string { return 'provider_capacity'; }

    public function evaluate(Program $program, ProgramNode $node, array $context = []): array
    {
        $enabled = is_array($context['enabledProviderIds'] ?? null) ? $context['enabledProviderIds'] : null;
        $provider = $node->providerId() ?? (is_string($context['defaultProviderId'] ?? null) ? $context['defaultProviderId'] : null);
        if ($enabled === null || $enabled === []) {
            return ['admit' => true, 'scoreDelta' => 0.5, 'reason' => 'no provider filter'];
        }
        if ($provider === null) {
            return ['admit' => true, 'scoreDelta' => 0.2, 'reason' => 'provider to be allocated'];
        }
        $ok = in_array($provider, $enabled, true);

        return [
            'admit' => $ok,
            'scoreDelta' => $ok ? 1.0 : 0.0,
            'reason' => $ok ? 'provider enabled' : 'provider disabled',
        ];
    }
}
