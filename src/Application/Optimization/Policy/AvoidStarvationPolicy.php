<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class AvoidStarvationPolicy implements OptimizationPolicy {
    public function id(): string { return 'avoid_starvation'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $wins = is_numeric($candidate['recentWins'] ?? null) ? (float) $candidate['recentWins'] : 0.0;
        $delta = max(0.0, 5.0 - $wins);
        return ['admit' => true, 'scoreDelta' => $delta, 'reason' => 'fairness'];
    }
}
