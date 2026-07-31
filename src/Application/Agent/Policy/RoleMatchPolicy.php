<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class RoleMatchPolicy implements AgentPolicy {
    public function id(): string { return 'role_match'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $role = is_string($request['role'] ?? null) ? $request['role'] : null;
        if ($role === null || $role === '') {
            return ['admit' => true, 'scoreDelta' => 0.0, 'reason' => 'any role'];
        }
        $ok = $candidate->role() === $role;
        return ['admit' => $ok, 'scoreDelta' => $ok ? 15.0 : 0.0, 'reason' => $ok ? 'role match' : 'role mismatch'];
    }
}
