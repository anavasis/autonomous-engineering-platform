<?php

declare(strict_types=1);

namespace Tests\Application\MissionExecution;

use Aep\Application\MissionExecution\Model\ProjectMemory;
use Aep\Infrastructure\MissionExecution\Understanding\HeuristicPromptUnderstanding;
use Tests\Support\Assert;

final class HeuristicUnderstandingTest
{
    public function test_example_mission_extracts_intent(): void
    {
        $u = new HeuristicPromptUnderstanding();
        $memory = new ProjectMemory('proj_1', ['Client Panel' => 'src/ClientPanel/']);
        $intent = $u->understand(
            "Fix the Client Panel email formatting bug.\nInspection first.\nDo not modify email formatting.",
            'proj_1',
            [['id' => 'proj_1', 'slug' => 'demo', 'displayName' => 'Demo']],
            $memory
        );

        Assert::true(str_contains(strtolower($intent->objective()), 'client panel'));
        Assert::same('fix', $intent->changeType());
        Assert::true($intent->inspectionFirst());
        Assert::contains('Client Panel', $intent->affectedAreas());
        Assert::true(count($intent->nonGoals()) >= 1);
        Assert::same('proj_1', $intent->projectId());
    }
}
