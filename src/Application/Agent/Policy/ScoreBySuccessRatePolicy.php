<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class ScoreBySuccessRatePolicy implements AgentPolicy {
    public function __construct(private readonly float $weight = 10.0) {}
    public function id(): string { return 'score_success_rate'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $m = $candidate->metrics();
        $ok = is_numeric($m['successCount'] ?? null) ? (float)$m['successCount'] : 0.0;
        $bad = is_numeric($m['failureCount'] ?? null) ? (float)$m['failureCount'] : 0.0;
        $rate = ($ok + $bad) > 0 ? $ok / ($ok + $bad) : $candidate->profile()->confidencePrior();
        return ['admit' => true, 'scoreDelta' => $this->weight * $rate, 'reason' => 'successRate=' . round($rate, 3)];
    }
}
