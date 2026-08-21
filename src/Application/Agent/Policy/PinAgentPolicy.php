<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class PinAgentPolicy implements AgentPolicy {
    public function id(): string { return 'pin_agent'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $pin = is_string($request['agentId'] ?? null) ? $request['agentId'] : null;
        if ($pin === null || $pin === '') {
            return ['admit' => true, 'scoreDelta' => 0.0, 'reason' => 'no pin'];
        }
        $ok = $candidate->agentId() === $pin;
        return ['admit' => $ok, 'scoreDelta' => $ok ? 100.0 : 0.0, 'reason' => $ok ? 'pinned' : 'not pinned'];
    }
}
