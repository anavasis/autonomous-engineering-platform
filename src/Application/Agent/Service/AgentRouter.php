<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Service;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Policy\AgentPolicySet;
use Aep\Application\Agent\Port\AgentRegistry;

/**
 * Selects agents by evaluating configured AgentPolicy rules — no hardcoded routing.
 */
final class AgentRouter
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly AgentPolicySet $policies,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array{agent: ?Agent, score: float, trace: list<array<string, mixed>>, candidates: int}
     */
    public function select(array $request): array
    {
        $role = is_string($request['role'] ?? null) ? $request['role'] : null;
        $capabilities = [];
        if (isset($request['capabilities']) && is_array($request['capabilities'])) {
            foreach ($request['capabilities'] as $c) {
                if (is_string($c)) {
                    $capabilities[] = $c;
                }
            }
        }
        $exclude = [];
        if (isset($request['excludeAgentIds']) && is_array($request['excludeAgentIds'])) {
            foreach ($request['excludeAgentIds'] as $id) {
                if (is_string($id)) {
                    $exclude[] = $id;
                }
            }
        }

        $candidates = $this->registry->candidates($role, $capabilities, true);
        $scored = [];
        $allTrace = [];
        foreach ($candidates as $agent) {
            if (in_array($agent->agentId(), $exclude, true)) {
                continue;
            }
            $admit = true;
            $score = 0.0;
            $trace = [];
            foreach ($this->policies->all() as $policy) {
                $result = $policy->evaluate($request, $agent, []);
                $passed = ($result['admit'] ?? true) === true;
                $delta = is_numeric($result['scoreDelta'] ?? null) ? (float) $result['scoreDelta'] : 0.0;
                $trace[] = [
                    'policy' => $policy->id(),
                    'admit' => $passed,
                    'scoreDelta' => $delta,
                    'reason' => is_string($result['reason'] ?? null) ? $result['reason'] : '',
                ];
                if (!$passed) {
                    $admit = false;
                }
                $score += $delta;
            }
            $allTrace[$agent->agentId()] = $trace;
            if ($admit) {
                $scored[] = ['agent' => $agent, 'score' => $score, 'trace' => $trace];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $winner = $scored[0] ?? null;

        return [
            'agent' => $winner['agent'] ?? null,
            'score' => $winner['score'] ?? 0.0,
            'trace' => $winner['trace'] ?? [],
            'candidates' => count($candidates),
            'policyTrace' => $allTrace,
        ];
    }
}
