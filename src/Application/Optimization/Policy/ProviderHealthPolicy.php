<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class ProviderHealthPolicy implements OptimizationPolicy {
    public function id(): string { return 'provider_health'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $state = is_string($candidate['state'] ?? null) ? $candidate['state'] : 'available';
        $ok = !in_array($state, ['offline', 'degraded'], true);
        return ['admit' => $ok, 'scoreDelta' => $ok ? 2.0 : 0.0, 'reason' => 'state=' . $state];
    }
}
