<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Agent;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Model\AgentSession;
use Aep\Infrastructure\Agent\Store\FilesystemAgentStore;
use Aep\Infrastructure\Agent\Store\JsonAgentSettingsStore;
use Tests\Support\Assert;

final class FilesystemAgentStoreTest
{
    public function test_persist_agent_assignment_session_events_and_catalog(): void
    {
        $root = sys_get_temp_dir() . '/aep_agt_store_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemAgentStore($root);
            $agent = Agent::fromArray([
                'agentId' => 'agt_store_1',
                'name' => 'Store Agent',
                'role' => Agent::ROLE_REVIEWER,
                'status' => Agent::STATUS_AVAILABLE,
                'createdAtUtc' => '2026-01-01T00:00:00Z',
                'updatedAtUtc' => '2026-01-01T00:00:00Z',
                'profile' => [
                    'capabilities' => [
                        ['capabilityId' => 'testing', 'proficiency' => 0.85],
                        ['capabilityId' => 'security', 'proficiency' => 0.7],
                    ],
                    'preferredProviders' => ['local-agent'],
                    'maxConcurrency' => 2,
                ],
            ])->withSealedHashes();
            $store->saveAgent($agent);

            $assignment = new AgentAssignment(
                'asgn_store_1',
                'agt_store_1',
                Agent::ROLE_REVIEWER,
                AgentAssignment::STATUS_STARTED,
                '2026-01-01T00:00:00Z',
                '2026-01-01T00:01:00Z',
                'prg_1',
                'node_1',
                'msn_1',
                'run_1',
                'asess_1',
                ['testing'],
            );
            $store->saveAssignment($assignment);

            $session = new AgentSession(
                'asess_1',
                'asgn_store_1',
                'agt_store_1',
                'open',
                '2026-01-01T00:01:00Z',
                '2026-01-01T00:01:00Z',
                'msn_1',
                'run_1',
            );
            $store->saveSession($session);

            $store->appendEvent(new AgentEvent(
                'aev_1',
                AgentEvent::ASSIGNMENT_STARTED,
                '2026-01-01T00:01:00Z',
                'agt_store_1',
                'asgn_store_1',
                'prg_1',
                'node_1',
                'msn_1',
            ));

            $found = $store->findAgent('agt_store_1');
            Assert::true($found !== null);
            Assert::same('agt_store_1', $found?->agentId());
            Assert::true(str_starts_with($found?->integrityHash() ?? '', 'sha256:'));
            Assert::true(count($store->listAgents(Agent::ROLE_REVIEWER)) >= 1);
            Assert::true(count($store->listAgents(null, null, 'testing')) >= 1);
            Assert::same('asgn_store_1', $store->findAssignment('asgn_store_1')?->assignmentId());
            Assert::true(count($store->listAssignments('prg_1')) >= 1);
            Assert::true(count($store->listAssignments(null, 'msn_1')) >= 1);
            Assert::true(count($store->listSessions('agt_store_1')) >= 1);
            Assert::true(count($store->events('agt_store_1')) >= 1);
            Assert::true(in_array('testing', $store->capabilityCatalog(), true));

            $settings = new JsonAgentSettingsStore($root);
            $defaults = $settings->get();
            Assert::same(true, $defaults['enabled']);
            $updated = $settings->put(['assignOnPlanningLaunch' => false]);
            Assert::same(false, $updated['assignOnPlanningLaunch']);
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
