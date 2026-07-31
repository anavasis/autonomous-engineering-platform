<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class LoadBalancingPolicy implements AgentPolicy {
    public function id(): string { return 'load_balancing'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $slots = $candidate->availableSlots();
        return ['admit' => true, 'scoreDelta' => (float)$slots * 2.0, 'reason' => 'slots=' . $slots];
    }
}
