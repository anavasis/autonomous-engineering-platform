<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class RequireAvailablePolicy implements AgentPolicy {
    public function id(): string { return 'require_available'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $ok = $candidate->availableSlots() > 0 && !in_array($candidate->status(), [Agent::STATUS_RETIRED, Agent::STATUS_OFFLINE], true);
        return ['admit' => $ok, 'scoreDelta' => $ok ? 1.0 : 0.0, 'reason' => $ok ? 'available' : 'unavailable'];
    }
}
