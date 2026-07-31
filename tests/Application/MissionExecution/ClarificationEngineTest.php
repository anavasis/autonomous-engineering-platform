<?php

declare(strict_types=1);

namespace Tests\Application\MissionExecution;

use Aep\Application\MissionExecution\Model\MissionIntent;
use Aep\Application\MissionExecution\Model\ProjectMemory;
use Aep\Application\MissionExecution\Service\ClarificationEngine;
use Tests\Support\Assert;

final class ClarificationEngineTest
{
    public function test_does_not_ask_when_context_complete(): void
    {
        $engine = new ClarificationEngine();
        $memory = new ProjectMemory('proj_1', ['Client Panel' => 'src/ClientPanel/'], ['src/ClientPanel/']);
        $intent = new MissionIntent(
            'Fix Client Panel email formatting',
            'fix',
            'proj_1',
            'demo',
            'github',
            'org/repo',
            ['Client Panel'],
            ['src/ClientPanel/'],
            ['Do not modify email formatting'],
            rawText: 'Fix Client Panel email formatting'
        );
        $projects = [[
            'id' => 'proj_1',
            'displayName' => 'Demo',
            'slug' => 'demo',
            'repositoryStatus' => 'bound',
            'repository' => ['provider' => 'github', 'repository' => 'org/repo'],
        ]];

        $enriched = $engine->enrich($intent, $projects, $memory);
        $questions = $engine->questions($enriched, $projects, $memory);
        Assert::same(0, count($questions));
    }

    public function test_asks_project_only_when_multiple_and_missing(): void
    {
        $engine = new ClarificationEngine();
        $intent = new MissionIntent('Fix something', rawText: 'Fix something');
        $projects = [
            ['id' => 'p1', 'displayName' => 'One', 'slug' => 'one', 'repositoryStatus' => 'bound'],
            ['id' => 'p2', 'displayName' => 'Two', 'slug' => 'two', 'repositoryStatus' => 'bound'],
        ];
        $questions = $engine->questions($intent, $projects, null);
        $fields = array_map(static fn ($q) => $q->field(), $questions);
        Assert::contains('projectId', $fields);
    }
}
