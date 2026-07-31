<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Port;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Model\AgentSession;

interface AgentStore
{
    public function saveAgent(Agent $agent): void;
    public function findAgent(string $agentId): ?Agent;
    /** @return list<Agent> */
    public function listAgents(?string $role = null, ?string $status = null, ?string $capability = null): array;

    public function saveAssignment(AgentAssignment $assignment): void;
    public function findAssignment(string $assignmentId): ?AgentAssignment;
    /** @return list<AgentAssignment> */
    public function listAssignments(?string $programId = null, ?string $missionId = null, ?string $agentId = null, ?string $status = null): array;

    public function saveSession(AgentSession $session): void;
    /** @return list<AgentSession> */
    public function listSessions(?string $agentId = null): array;

    public function appendEvent(AgentEvent $event): void;
    /** @return list<AgentEvent> */
    public function events(?string $agentId = null, int $limit = 200): array;

    /** @return list<string> */
    public function capabilityCatalog(): array;
}
