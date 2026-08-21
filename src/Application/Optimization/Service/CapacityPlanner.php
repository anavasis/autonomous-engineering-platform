<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Service;

use Aep\Application\Optimization\Port\OptimizationStore;

final class CapacityPlanner
{
    public function __construct(private readonly OptimizationStore $store) {}

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    public function project(array $intent): array
    {
        $providers = $this->store->listProviderCapacity();
        $availableProviderSlots = 0;
        foreach ($providers as $p) {
            $availableProviderSlots += $p->availableSlots();
        }
        $ws = $this->store->workspaceCapacity();
        $agents = $this->store->listAgentCapacity();
        $agentSlots = 0;
        foreach ($agents as $a) {
            $agentSlots += $a->availableSlots();
        }
        $readyNodes = is_int($intent['readyNodeCount'] ?? null) ? $intent['readyNodeCount'] : 1;
        $contention = $readyNodes > $availableProviderSlots;

        return [
            'availableProviderSlots' => $availableProviderSlots,
            'availableAgentSlots' => $agentSlots,
            'workspaceSlots' => $ws->availableSlots(),
            'readyNodeCount' => $readyNodes,
            'contention' => $contention,
            'advice' => $contention ? 'delay' : 'launch',
        ];
    }
}
