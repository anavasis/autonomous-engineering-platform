<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Governance\Adapter;

use Aep\Application\Governance\Port\GovernanceSettingsStore;
use Aep\Application\Governance\Service\GovernanceObserveFacade;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;

/**
 * Decorates PlanningLaunchPort: observes launches into governance change requests.
 * Does not alter Planning scheduler or Mission Engine semantics.
 */
final class GovernanceLaunchAdapter implements PlanningLaunchPort
{
    public function __construct(
        private readonly PlanningLaunchPort $inner,
        private readonly GovernanceObserveFacade $observe,
        private readonly GovernanceSettingsStore $settings,
    ) {}

    public function launchNode(Program $program, ProgramNode $node, string $actorId): array
    {
        $result = $this->inner->launchNode($program, $node, $actorId);
        $settings = $this->settings->get();
        if (($settings['enabled'] ?? true) === true && ($settings['observePlanningLaunch'] ?? false) === true) {
            try {
                $this->observe->onPlanningLaunch([
                    'programId' => $program->programId(),
                    'nodeId' => $node->nodeId(),
                    'missionId' => $result['missionId'] ?? null,
                    'runId' => $result['runId'] ?? null,
                ], $actorId);
            } catch (\Throwable) {
            }
        }
        return $result;
    }
}
