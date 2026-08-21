<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Optimization\Adapter;

use Aep\Application\Optimization\Port\OptimizationSettingsStore;
use Aep\Application\Optimization\Service\OptimizationEngine;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;

/**
 * Decorates PlanningLaunchPort: consults OptimizationEngine before launch.
 * Does not alter Planning scheduler semantics or Mission Engine contracts.
 */
final class OptimizationPlanningAdapter implements PlanningLaunchPort
{
    public function __construct(
        private readonly PlanningLaunchPort $inner,
        private readonly OptimizationEngine $engine,
        private readonly OptimizationSettingsStore $settings,
    ) {}

    public function launchNode(Program $program, ProgramNode $node, string $actorId): array
    {
        $settings = $this->settings->get();
        if (($settings['enabled'] ?? true) !== true || ($settings['assistPlanning'] ?? true) !== true) {
            return $this->inner->launchNode($program, $node, $actorId);
        }

        $constraints = $node->constraints();
        $preferred = is_string($constraints['providerId'] ?? null) ? $constraints['providerId'] : null;
        try {
            $decision = $this->engine->decide([
                'programId' => $program->programId(),
                'nodeId' => $node->nodeId(),
                'priority' => $node->priority(),
                'preferredProviderId' => $preferred,
                'role' => is_string($constraints['agentRole'] ?? null) ? $constraints['agentRole'] : null,
                'tokens' => 2000,
                'minutes' => 1,
            ], true);
            if (!$decision->admit() && ($settings['failOpen'] ?? true) !== true) {
                return [
                    'missionId' => '',
                    'runId' => '',
                    'message' => 'Optimization delayed/denied: ' . $decision->reason(),
                ];
            }
        } catch (\Throwable) {
            // best-effort
        }

        return $this->inner->launchNode($program, $node, $actorId);
    }
}
