<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class EcoModePolicy implements OptimizationPolicy {
    public function __construct(private bool $enabled = false) {}
    public function id(): string { return 'eco_mode'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        if (!$this->enabled) {
            return ['admit' => true, 'scoreDelta' => 0.0, 'reason' => 'eco off'];
        }
        $cost = is_numeric($candidate['estimatedCost'] ?? null) ? (float) $candidate['estimatedCost'] : 1.0;
        return ['admit' => true, 'scoreDelta' => 20.0 / max(0.0001, $cost), 'reason' => 'eco'];
    }
}
