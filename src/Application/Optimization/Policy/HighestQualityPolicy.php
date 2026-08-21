<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class HighestQualityPolicy implements OptimizationPolicy {
    public function __construct(private float $weight = 12.0) {}
    public function id(): string { return 'highest_quality'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $success = is_numeric($candidate['successRate'] ?? null) ? (float) $candidate['successRate'] : 0.5;
        $conf = is_numeric($candidate['confidence'] ?? null) ? (float) $candidate['confidence'] : 0.5;
        return ['admit' => true, 'scoreDelta' => $this->weight * $success * $conf, 'reason' => 'quality'];
    }
}
