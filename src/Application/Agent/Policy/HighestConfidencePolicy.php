<?php
declare(strict_types=1);
namespace Aep\Application\Agent\Policy;
use Aep\Application\Agent\Model\Agent;
final class HighestConfidencePolicy implements AgentPolicy {
    public function __construct(private readonly float $weight = 8.0) {}
    public function id(): string { return 'highest_confidence'; }
    public function evaluate(array $request, Agent $candidate, array $context = []): array {
        return ['admit' => true, 'scoreDelta' => $this->weight * $candidate->profile()->confidencePrior(), 'reason' => 'confidence prior'];
    }
}
