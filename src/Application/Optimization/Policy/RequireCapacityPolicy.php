<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class RequireCapacityPolicy implements OptimizationPolicy {
    public function id(): string { return 'require_capacity'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $ok = ($candidate['routable'] ?? false) === true;
        return ['admit' => $ok, 'scoreDelta' => $ok ? 1.0 : 0.0, 'reason' => $ok ? 'capacity available' : 'no capacity'];
    }
}
