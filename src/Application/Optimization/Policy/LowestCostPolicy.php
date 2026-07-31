<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class LowestCostPolicy implements OptimizationPolicy {
    public function __construct(private float $weight = 10.0) {}
    public function id(): string { return 'lowest_cost'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $cost = is_numeric($candidate['estimatedCost'] ?? null) ? (float) $candidate['estimatedCost'] : 1.0;
        $delta = $this->weight / max(0.0001, $cost);
        return ['admit' => true, 'scoreDelta' => $delta, 'reason' => 'cost=' . $cost];
    }
}
