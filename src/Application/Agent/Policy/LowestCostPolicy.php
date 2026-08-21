<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class LowestCostPolicy implements AgentPolicy {
    public function __construct(private readonly float $weight = 5.0) {}
    public function id(): string { return 'lowest_cost'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        $cost = max(0.1, $candidate->profile()->costWeight());
        return ['admit' => true, 'scoreDelta' => $this->weight / $cost, 'reason' => 'costWeight=' . $cost];
    }
}
