<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Agent;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Policy\AgentPolicyFactory;
use Aep\Application\Agent\Service\AgentCoordinator;
use Aep\Application\Agent\Service\AgentRouter;
use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;
use Aep\Infrastructure\Agent\Adapter\AgentAssignmentAdapter;
use Aep\Infrastructure\Agent\Registry\ConfigAgentRegistry;
use Aep\Infrastructure\Agent\Store\FilesystemAgentStore;
use Aep\Infrastructure\Agent\Store\JsonAgentSettingsStore;
use Tests\Support\Assert;

final class AgentAssignmentAdapterTest
{
    public function test_assigns_agent_around_planning_launch(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_adapt_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemAgentStore($root);
            $settings = new JsonAgentSettingsStore($root);
            $registry = new ConfigAgentRegistry($store, [
                'agents' => [
                    [
                        'id' => 'agt_impl_adapt',
                        'displayName' => 'Implementer',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                    ],
                ],
            ]);
            $router = new AgentRouter($registry, AgentPolicyFactory::fromSettings($settings->get()));
            $coordinator = new AgentCoordinator($store, $settings, $router);

            $inner = new class implements PlanningLaunchPort {
                public function launchNode(Program $program, ProgramNode $node, string $actorId): array
                {
                    return [
                        'missionId' => 'msn_adapt_1',
                        'runId' => 'run_adapt_1',
                        'nodeId' => $node->nodeId(),
                    ];
                }
            };

            $adapter = new AgentAssignmentAdapter($inner, $coordinator, $settings);
            $node = new ProgramNode(
                'node_adapt_1',
                'Implement feature module',
                'implement php feature changes for adapter probe',
                ProgramNode::STATUS_PENDING,
                [],
                'normal',
            );
            $program = new Program(
                'prg_adapt_1',
                'Adapter Program',
                'implement php feature changes for adapter probe',
                Program::STATUS_RUNNING,
                '2026-01-01T00:00:00Z',
                '2026-01-01T00:00:00Z',
                new MissionGraph([$node], []),
            );

            $result = $adapter->launchNode($program, $node, 'tester');
            Assert::same('msn_adapt_1', $result['missionId']);
            $items = $store->listAssignments('prg_adapt_1', 'msn_adapt_1');
            Assert::true(count($items) >= 1);
            Assert::same('agt_impl_adapt', $items[0]->agentId());
            Assert::same(AgentAssignment::STATUS_STARTED, $items[0]->status());
            Assert::same(Agent::ROLE_IMPLEMENTER, $items[0]->role());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_feature_flag_skips_assignment(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_adapt_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemAgentStore($root);
            $settings = new JsonAgentSettingsStore($root);
            $settings->put(['enabled' => false]);
            $registry = new ConfigAgentRegistry($store, [
                'agents' => [
                    [
                        'id' => 'agt_impl_off',
                        'displayName' => 'Implementer',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                    ],
                ],
            ]);
            $router = new AgentRouter($registry, AgentPolicyFactory::fromSettings($settings->get()));
            $coordinator = new AgentCoordinator($store, $settings, $router);
            $inner = new class implements PlanningLaunchPort {
                public function launchNode(Program $program, ProgramNode $node, string $actorId): array
                {
                    return ['missionId' => 'msn_off', 'runId' => 'run_off', 'nodeId' => $node->nodeId()];
                }
            };
            $adapter = new AgentAssignmentAdapter($inner, $coordinator, $settings);
            $node = new ProgramNode('n1', 'Implement', 'implement something');
            $program = new Program(
                'prg_off',
                'Off',
                'implement something',
                Program::STATUS_RUNNING,
                '2026-01-01T00:00:00Z',
                '2026-01-01T00:00:00Z',
                new MissionGraph([$node], []),
            );
            $adapter->launchNode($program, $node, 'tester');
            Assert::same(0, count($store->listAssignments('prg_off')));
        } finally {
            $this->removeDir($root);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
