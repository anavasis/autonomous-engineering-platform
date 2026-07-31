<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\Workflow\DefaultMissionWorkflow;

/**
 * Selects a catalog workflow without modifying Workflow semantics.
 */
final class WorkflowSelector
{
    /**
     * @return array{workflowId: string, version: string, reason: string}
     */
    public function select(MissionIntent $intent): array
    {
        // 0.2: only aep.default_mission is available via DefaultMissionWorkflow.
        unset($intent);
        $json = json_decode(DefaultMissionWorkflow::json(), true);
        $id = is_array($json) && is_string($json['workflow'] ?? null) ? $json['workflow'] : 'aep.default_mission';
        $version = is_array($json) && is_string($json['version'] ?? null) ? $json['version'] : '1.0.0';

        return [
            'workflowId' => $id,
            'version' => $version,
            'reason' => 'Selected aep.default_mission@' . $version . ' — the canonical inspection→implement→validate→commit workflow for controlled engineering changes.',
        ];
    }
}
