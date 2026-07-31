<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Agent\Registry;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Port\AgentRegistry;
use Aep\Application\Agent\Port\AgentStore;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Config + store backed registry. Seeds agents from deploy/agents.json on first boot.
 */
final class ConfigAgentRegistry implements AgentRegistry
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly AgentStore $store,
        array $config = [],
    ) {
        $this->seed($config);
    }

    public function get(string $agentId): ?Agent
    {
        return $this->store->findAgent($agentId);
    }

    public function candidates(?string $role = null, array $capabilities = [], bool $routableOnly = true): array
    {
        // Broaden by role (or all), then let policies admit/score by capability.
        $agents = $this->store->listAgents($role);
        $out = [];
        foreach ($agents as $agent) {
            if ($routableOnly && !$agent->isRoutable()) {
                continue;
            }
            $out[] = $agent;
        }

        return $out;
    }

    public function list(): array
    {
        return array_map(static fn (Agent $a) => $a->toArray(), $this->store->listAgents());
    }

    /** @param array<string, mixed> $config */
    private function seed(array $config): void
    {
        $agents = is_array($config['agents'] ?? null) ? $config['agents'] : [];
        if ($agents === []) {
            return;
        }
        foreach ($agents as $entry) {
            if (!is_array($entry) || ($entry['enabled'] ?? true) !== true) {
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            if ($id === '' || $this->store->findAgent($id) !== null) {
                continue;
            }
            $at = Utc::now();
            $agent = Agent::fromArray([
                'agentId' => $id,
                'name' => is_string($entry['displayName'] ?? null) ? $entry['displayName'] : $id,
                'role' => is_string($entry['role'] ?? null) ? $entry['role'] : Agent::ROLE_IMPLEMENTER,
                'status' => Agent::STATUS_AVAILABLE,
                'createdAtUtc' => $at,
                'updatedAtUtc' => $at,
                'profile' => is_array($entry['profile'] ?? null) ? $entry['profile'] : [
                    'capabilities' => array_map(
                        static fn (string $c): array => ['capabilityId' => $c, 'proficiency' => 0.8],
                        is_array($entry['capabilities'] ?? null) ? array_values(array_filter($entry['capabilities'], 'is_string')) : []
                    ),
                    'preferredProviders' => is_array($entry['preferredProviders'] ?? null) ? $entry['preferredProviders'] : ['local-agent'],
                    'costWeight' => $entry['costWeight'] ?? 1.0,
                    'confidencePrior' => $entry['confidencePrior'] ?? 0.75,
                    'maxConcurrency' => $entry['maxConcurrency'] ?? 2,
                ],
            ])->withSealedHashes();
            $this->store->saveAgent($agent);
            $this->store->appendEvent(new AgentEvent(
                AgentEvent::makeId(),
                AgentEvent::AGENT_REGISTERED,
                $at,
                $agent->agentId(),
                null,
                null,
                null,
                null,
                ['seeded' => true, 'role' => $agent->role()],
            ));
        }
    }
}
