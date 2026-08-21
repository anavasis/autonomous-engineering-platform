<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class RequireHealthyPolicy implements AgentPolicy {
    public function id(): string { return 'require_healthy'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $ok = $candidate->health()->isHealthy();
        return ['admit' => $ok, 'scoreDelta' => $ok ? 1.0 : 0.0, 'reason' => $ok ? 'healthy' : 'unhealthy'];
    }
}
