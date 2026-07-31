<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class CapabilityMatchPolicy implements AgentPolicy {
    public function id(): string { return 'capability_match'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $required = is_array($request['capabilities'] ?? null) ? $request['capabilities'] : [];
        if ($required === []) {
            return ['admit' => true, 'scoreDelta' => 0.5, 'reason' => 'no capability filter'];
        }
        $have = $candidate->profile()->capabilityIds();
        $hits = 0;
        foreach ($required as $cap) {
            if (is_string($cap) && in_array($cap, $have, true)) { $hits++; }
        }
        $ratio = $hits / max(1, count($required));
        return ['admit' => $hits > 0, 'scoreDelta' => 20.0 * $ratio, 'reason' => 'capability hits=' . $hits];
    }
}
