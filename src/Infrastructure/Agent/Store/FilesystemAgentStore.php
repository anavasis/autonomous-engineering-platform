<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Agent\Store;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Model\AgentSession;
use Aep\Application\Agent\Port\AgentStore;

final class FilesystemAgentStore implements AgentStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/agents',
            $this->root . '/assignments',
            $this->root . '/sessions',
            $this->root . '/events',
            $this->root . '/index/by-role',
            $this->root . '/index/by-capability',
            $this->root . '/index/by-status',
            $this->root . '/index/by-program',
            $this->root . '/index/by-mission',
            $this->root . '/capabilities',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create agent store: ' . $dir);
            }
        }
        $catalog = $this->root . '/capabilities/catalog.json';
        if (!is_file($catalog)) {
            file_put_contents($catalog, json_encode([
                'php', 'react', 'security', 'architecture', 'testing',
                'documentation', 'performance', 'database', 'deployment',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function saveAgent(Agent $agent): void
    {
        $dir = $this->root . '/agents/' . $this->safe($agent->agentId());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create agent dir.');
        }
        $data = $agent->toArray();
        file_put_contents($dir . '/agent.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/profile.json', json_encode($agent->profile()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/health.json', json_encode($agent->health()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($dir . '/metrics.json', json_encode($agent->metrics(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $idx = json_encode([
            'agentId' => $agent->agentId(),
            'role' => $agent->role(),
            'status' => $agent->status(),
            'name' => $agent->name(),
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/index/by-role/' . $this->safe($agent->role()) . '__' . $this->safe($agent->agentId()) . '.json', $idx);
        file_put_contents($this->root . '/index/by-status/' . $this->safe($agent->status()) . '__' . $this->safe($agent->agentId()) . '.json', $idx);
        foreach ($agent->profile()->capabilityIds() as $cap) {
            file_put_contents(
                $this->root . '/index/by-capability/' . $this->safe($cap) . '__' . $this->safe($agent->agentId()) . '.json',
                $idx
            );
        }
    }

    public function findAgent(string $agentId): ?Agent
    {
        $path = $this->root . '/agents/' . $this->safe($agentId) . '/agent.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? Agent::fromArray($data) : null;
    }

    public function listAgents(?string $role = null, ?string $status = null, ?string $capability = null): array
    {
        $out = [];
        foreach (glob($this->root . '/agents/*/agent.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $agent = Agent::fromArray($data);
            if ($role !== null && $role !== '' && $agent->role() !== $role) {
                continue;
            }
            if ($status !== null && $status !== '' && $agent->status() !== $status) {
                continue;
            }
            if ($capability !== null && $capability !== '' && !in_array($capability, $agent->profile()->capabilityIds(), true)) {
                continue;
            }
            $out[] = $agent;
        }
        usort($out, static fn (Agent $a, Agent $b): int => strcmp($a->name(), $b->name()));

        return $out;
    }

    public function saveAssignment(AgentAssignment $assignment): void
    {
        file_put_contents(
            $this->root . '/assignments/' . $this->safe($assignment->assignmentId()) . '.json',
            json_encode($assignment->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
        if ($assignment->programId()) {
            file_put_contents(
                $this->root . '/index/by-program/' . $this->safe($assignment->programId()) . '__' . $this->safe($assignment->assignmentId()) . '.json',
                json_encode(['assignmentId' => $assignment->assignmentId(), 'programId' => $assignment->programId()], JSON_THROW_ON_ERROR)
            );
        }
        if ($assignment->missionId()) {
            file_put_contents(
                $this->root . '/index/by-mission/' . $this->safe($assignment->missionId()) . '__' . $this->safe($assignment->assignmentId()) . '.json',
                json_encode(['assignmentId' => $assignment->assignmentId(), 'missionId' => $assignment->missionId()], JSON_THROW_ON_ERROR)
            );
        }
    }

    public function findAssignment(string $assignmentId): ?AgentAssignment
    {
        $path = $this->root . '/assignments/' . $this->safe($assignmentId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? AgentAssignment::fromArray($data) : null;
    }

    public function listAssignments(?string $programId = null, ?string $missionId = null, ?string $agentId = null, ?string $status = null): array
    {
        $out = [];
        foreach (glob($this->root . '/assignments/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $a = AgentAssignment::fromArray($data);
            if ($programId !== null && $programId !== '' && $a->programId() !== $programId) {
                continue;
            }
            if ($missionId !== null && $missionId !== '' && $a->missionId() !== $missionId) {
                continue;
            }
            if ($agentId !== null && $agentId !== '' && $a->agentId() !== $agentId) {
                continue;
            }
            if ($status !== null && $status !== '' && $a->status() !== $status) {
                continue;
            }
            $out[] = $a;
        }
        usort($out, static fn (AgentAssignment $a, AgentAssignment $b): int => strcmp($b->toArray()['updatedAtUtc'], $a->toArray()['updatedAtUtc']));

        return $out;
    }

    public function saveSession(AgentSession $session): void
    {
        file_put_contents(
            $this->root . '/sessions/' . $this->safe($session->sessionId()) . '.json',
            json_encode($session->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    public function listSessions(?string $agentId = null): array
    {
        $out = [];
        foreach (glob($this->root . '/sessions/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $s = AgentSession::fromArray($data);
            if ($agentId !== null && $agentId !== '' && $s->agentId() !== $agentId) {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    public function appendEvent(AgentEvent $event): void
    {
        $global = $this->root . '/events/timeline.jsonl';
        file_put_contents($global, json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        if ($event->agentId()) {
            file_put_contents(
                $this->root . '/events/' . $this->safe($event->agentId()) . '.jsonl',
                json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n",
                FILE_APPEND
            );
        }
    }

    public function events(?string $agentId = null, int $limit = 200): array
    {
        $path = $agentId
            ? $this->root . '/events/' . $this->safe($agentId) . '.jsonl'
            : $this->root . '/events/timeline.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $items[] = AgentEvent::fromArray($data);
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function capabilityCatalog(): array
    {
        $path = $this->root . '/capabilities/catalog.json';
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $c) {
            if (is_string($c)) {
                $out[] = $c;
            }
        }
        foreach ($this->listAgents() as $agent) {
            foreach ($agent->profile()->capabilityIds() as $cap) {
                $out[] = $cap;
            }
        }

        return array_values(array_unique($out));
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: 'unknown';
    }
}
