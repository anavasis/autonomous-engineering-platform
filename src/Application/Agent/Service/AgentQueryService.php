<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Service;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Port\AgentSettingsStore;
use Aep\Application\Agent\Port\AgentStore;

final class AgentQueryService
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly AgentSettingsStore $settings,
        private readonly AgentCoordinator $coordinator,
        private readonly AgentRouter $router,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $role = null, ?string $status = null, ?string $capability = null): array
    {
        return array_map(static fn (Agent $a) => self::summary($a), $this->store->listAgents($role, $status, $capability));
    }

    /** @return array<string, mixed>|null */
    public function get(string $agentId): ?array
    {
        $agent = $this->store->findAgent($agentId);
        if ($agent === null) {
            return null;
        }
        $data = $agent->toArray();
        $data['timeline'] = $this->timeline($agentId);
        $data['sessions'] = array_map(static fn ($s) => $s->toArray(), $this->store->listSessions($agentId));

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function assignments(?string $programId = null, ?string $missionId = null, ?string $agentId = null, ?string $status = null): array
    {
        return array_map(
            static fn (AgentAssignment $a) => $a->toArray(),
            $this->store->listAssignments($programId, $missionId, $agentId, $status)
        );
    }

    /** @return array<string, mixed>|null */
    public function assignment(string $assignmentId): ?array
    {
        return $this->store->findAssignment($assignmentId)?->toArray();
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->store->capabilityCatalog();
    }

    /**
     * Timeline generated from AgentEvents.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(?string $agentId = null, int $limit = 200): array
    {
        return array_map(
            static fn (AgentEvent $e) => $e->toArray() + ['message' => $e->type()],
            $this->store->events($agentId, $limit)
        );
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $agents = $this->store->listAgents();
        $byStatus = [];
        $unhealthy = 0;
        foreach ($agents as $a) {
            $byStatus[$a->status()] = ($byStatus[$a->status()] ?? 0) + 1;
            if (!$a->health()->isHealthy() || $a->status() === Agent::STATUS_OFFLINE) {
                $unhealthy++;
            }
        }
        $waiting = count($this->store->listAssignments(null, null, null, AgentAssignment::STATUS_WAITING));
        $active = count($this->store->listAssignments(null, null, null, AgentAssignment::STATUS_STARTED));

        return [
            'agentCount' => count($agents),
            'byStatus' => $byStatus,
            'unhealthy' => $unhealthy,
            'activeAssignments' => $active,
            'waitingAssignments' => $waiting,
            'items' => array_map(static fn (Agent $a) => self::summary($a), array_slice($agents, 0, 30)),
        ];
    }

    /** @return array<string, mixed> */
    public function metrics(string $agentId): array
    {
        $agent = $this->store->findAgent($agentId);
        if ($agent === null) {
            return [];
        }

        return [
            'agentId' => $agentId,
            'metrics' => $agent->metrics(),
            'health' => $agent->health()->toArray(),
            'availableSlots' => $agent->availableSlots(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
    {
        return $this->coordinator->register($input)->toArray();
    }

    /** @param array<string, mixed> $request */
    public function assign(array $request): array
    {
        return $this->coordinator->assign($request)->toArray();
    }

    public function complete(string $assignmentId, string $message = ''): array
    {
        return $this->coordinator->complete($assignmentId, $message)->toArray();
    }

    public function fail(string $assignmentId, string $message = ''): array
    {
        return $this->coordinator->fail($assignmentId, $message)->toArray();
    }

    public function reassign(string $assignmentId): array
    {
        return $this->coordinator->reassign($assignmentId)->toArray();
    }

    public function healthCheck(string $agentId): array
    {
        return $this->coordinator->healthCheck($agentId)->toArray();
    }

    public function retire(string $agentId): array
    {
        return $this->coordinator->retire($agentId)->toArray();
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings->get();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function updateSettings(array $settings): array
    {
        return $this->settings->put($settings);
    }

    public function coordinator(): AgentCoordinator
    {
        return $this->coordinator;
    }

    public function router(): AgentRouter
    {
        return $this->router;
    }

    /** @return array<string, mixed> */
    private static function summary(Agent $a): array
    {
        $d = $a->toArray();

        return [
            'agentId' => $d['agentId'],
            'name' => $d['name'],
            'role' => $d['role'],
            'status' => $d['status'],
            'capabilities' => $a->profile()->capabilityIds(),
            'availableSlots' => $d['availableSlots'],
            'health' => $d['health']['status'] ?? 'unknown',
            'reproducibilityFingerprint' => $d['reproducibilityFingerprint'],
            'integrityHash' => $d['integrityHash'],
            'schemaVersion' => $d['schemaVersion'],
            'updatedAtUtc' => $d['updatedAtUtc'],
        ];
    }
}
