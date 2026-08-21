<?php

declare(strict_types=1);

namespace Tests\Application\Agent;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Policy\AgentPolicyFactory;
use Aep\Application\Agent\Service\AgentCoordinator;
use Aep\Application\Agent\Service\AgentQueryService;
use Aep\Application\Agent\Service\AgentRouter;
use Aep\Infrastructure\Agent\Registry\ConfigAgentRegistry;
use Aep\Infrastructure\Agent\Store\FilesystemAgentStore;
use Aep\Infrastructure\Agent\Store\JsonAgentSettingsStore;
use Tests\Support\Assert;

final class AgentCoordinatorTest
{
    public function test_register_assign_start_complete_lifecycle(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_' . bin2hex(random_bytes(4));
        try {
            $svc = $this->services($root);
            $agent = $svc['coordinator']->register([
                'agentId' => 'agt_impl_1',
                'name' => 'Implementer One',
                'role' => Agent::ROLE_IMPLEMENTER,
                'profile' => [
                    'capabilities' => [
                        ['capabilityId' => 'php', 'proficiency' => 0.9],
                    ],
                    'preferredProviders' => ['local-agent'],
                    'maxConcurrency' => 2,
                    'costWeight' => 1.0,
                    'confidencePrior' => 0.8,
                ],
            ]);
            Assert::same('agt_impl_1', $agent->agentId());
            Assert::true(str_starts_with($agent->reproducibilityFingerprint(), 'sha256:'));
            Assert::true(str_starts_with($agent->integrityHash(), 'sha256:'));
            Assert::same(Agent::STATUS_AVAILABLE, $agent->status());

            $assignment = $svc['coordinator']->assign([
                'role' => Agent::ROLE_IMPLEMENTER,
                'capabilities' => ['php'],
                'programId' => 'prg_1',
                'nodeId' => 'node_1',
            ]);
            Assert::true(str_starts_with($assignment->assignmentId(), 'asgn_'));
            Assert::same('agt_impl_1', $assignment->agentId());
            Assert::same(AgentAssignment::STATUS_RESERVED, $assignment->status());

            $started = $svc['coordinator']->start($assignment, 'msn_1', 'run_1');
            Assert::same(AgentAssignment::STATUS_STARTED, $started->status());
            Assert::same('msn_1', $started->missionId());
            Assert::true($started->sessionId() !== null && $started->sessionId() !== '');

            $done = $svc['coordinator']->complete($started->assignmentId(), 'ok');
            Assert::same(AgentAssignment::STATUS_COMPLETED, $done->status());

            $types = array_map(
                static fn (AgentEvent $e) => $e->type(),
                $svc['store']->events('agt_impl_1')
            );
            Assert::true(in_array(AgentEvent::AGENT_REGISTERED, $types, true));
            Assert::true(in_array(AgentEvent::ASSIGNMENT_CREATED, $types, true));
            Assert::true(in_array(AgentEvent::ASSIGNMENT_STARTED, $types, true));
            Assert::true(in_array(AgentEvent::ASSIGNMENT_COMPLETED, $types, true));

            $metrics = $svc['query']->metrics('agt_impl_1');
            Assert::same(1, $metrics['metrics']['successCount'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_policy_driven_routing_prefers_role_and_capability(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_' . bin2hex(random_bytes(4));
        try {
            $svc = $this->services($root, [
                'agents' => [
                    [
                        'id' => 'agt_arch',
                        'displayName' => 'Architect',
                        'role' => 'architect',
                        'capabilities' => ['architecture'],
                        'confidencePrior' => 0.9,
                    ],
                    [
                        'id' => 'agt_impl',
                        'displayName' => 'Implementer',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                        'confidencePrior' => 0.5,
                    ],
                ],
            ]);
            $selected = $svc['router']->select([
                'role' => 'architect',
                'capabilities' => ['architecture'],
            ]);
            Assert::true($selected['agent'] instanceof Agent);
            Assert::same('agt_arch', $selected['agent']->agentId());
            Assert::true(count($selected['trace']) >= 1);
            $policyIds = array_map(static fn (array $row) => $row['policy'], $selected['trace']);
            Assert::true(in_array('role_match', $policyIds, true));
            Assert::true(in_array('capability_match', $policyIds, true));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_reassign_creates_new_assignment_and_supersedes_old(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_' . bin2hex(random_bytes(4));
        try {
            $svc = $this->services($root, [
                'agents' => [
                    [
                        'id' => 'agt_a',
                        'displayName' => 'A',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                    ],
                    [
                        'id' => 'agt_b',
                        'displayName' => 'B',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                    ],
                ],
            ]);
            $first = $svc['coordinator']->assign([
                'role' => 'implementer',
                'capabilities' => ['php'],
                'programId' => 'prg_x',
            ]);
            $second = $svc['coordinator']->reassign($first->assignmentId());
            Assert::true($second->assignmentId() !== $first->assignmentId());
            Assert::true($second->agentId() !== $first->agentId());
            $old = $svc['store']->findAssignment($first->assignmentId());
            Assert::true($old !== null);
            Assert::same(AgentAssignment::STATUS_REASSIGNED, $old?->status());
            Assert::same($second->assignmentId(), $old?->toArray()['supersededBy'] ?? null);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_pin_agent_policy_forces_selection(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_' . bin2hex(random_bytes(4));
        try {
            $svc = $this->services($root, [
                'agents' => [
                    [
                        'id' => 'agt_a',
                        'displayName' => 'A',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                        'confidencePrior' => 0.99,
                    ],
                    [
                        'id' => 'agt_b',
                        'displayName' => 'B',
                        'role' => 'implementer',
                        'capabilities' => ['php'],
                        'confidencePrior' => 0.1,
                    ],
                ],
            ]);
            $selected = $svc['router']->select([
                'role' => 'implementer',
                'agentId' => 'agt_b',
            ]);
            Assert::same('agt_b', $selected['agent']?->agentId());
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_dashboard_and_settings_feature_flag(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_' . bin2hex(random_bytes(4));
        try {
            $svc = $this->services($root, [
                'agents' => [
                    [
                        'id' => 'agt_qa',
                        'displayName' => 'QA',
                        'role' => 'qa',
                        'capabilities' => ['testing'],
                    ],
                ],
            ]);
            $dash = $svc['query']->dashboard();
            Assert::same(1, $dash['agentCount']);
            $settings = $svc['query']->updateSettings(['enabled' => false, 'assignOnPlanningLaunch' => false]);
            Assert::same(false, $settings['enabled']);
            Assert::same(false, $settings['assignOnPlanningLaunch']);
        } finally {
            $this->removeDir($root);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   store: FilesystemAgentStore,
     *   coordinator: AgentCoordinator,
     *   router: AgentRouter,
     *   query: AgentQueryService
     * }
     */
    private function services(string $root, array $config = ['agents' => []]): array
    {
        $store = new FilesystemAgentStore($root);
        $settings = new JsonAgentSettingsStore($root);
        $registry = new ConfigAgentRegistry($store, $config);
        $policies = AgentPolicyFactory::fromSettings($settings->get());
        $router = new AgentRouter($registry, $policies);
        $coordinator = new AgentCoordinator($store, $settings, $router);
        $query = new AgentQueryService($store, $settings, $coordinator, $router);

        return [
            'store' => $store,
            'coordinator' => $coordinator,
            'router' => $router,
            'query' => $query,
        ];
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
