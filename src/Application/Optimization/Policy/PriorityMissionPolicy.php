<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class PriorityMissionPolicy implements OptimizationPolicy {
    public function id(): string { return 'priority_mission'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $priority = is_string($intent['priority'] ?? null) ? $intent['priority'] : 'normal';
        $boost = match ($priority) { 'emergency' => 30.0, 'high' => 15.0, 'low' => -5.0, default => 0.0 };
        return ['admit' => true, 'scoreDelta' => $boost, 'reason' => 'priority=' . $priority];
    }
}
