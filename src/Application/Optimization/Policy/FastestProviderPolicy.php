<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class FastestProviderPolicy implements OptimizationPolicy {
    public function __construct(private float $weight = 8.0) {}
    public function id(): string { return 'fastest_provider'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $latency = is_numeric($candidate['avgLatencyMs'] ?? null) ? (float) $candidate['avgLatencyMs'] : 1000.0;
        return ['admit' => true, 'scoreDelta' => $this->weight * (1000.0 / max(1.0, $latency)), 'reason' => 'latency'];
    }
}
