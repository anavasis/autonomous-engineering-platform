<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;
final class RequireBudgetPolicy implements OptimizationPolicy {
    public function id(): string { return 'require_budget'; }
    public function evaluate(array $intent, array $candidate, array $context = []): array {
        $ok = ($context['budgetOk'] ?? true) === true;
        return ['admit' => $ok, 'scoreDelta' => $ok ? 1.0 : 0.0, 'reason' => $ok ? 'budget ok' : 'budget exceeded'];
    }
}
