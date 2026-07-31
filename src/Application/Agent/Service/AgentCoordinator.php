<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Service;

use Aep\Application\Agent\Model\Agent;
use Aep\Application\Agent\Model\AgentAssignment;
use Aep\Application\Agent\Model\AgentEvent;
use Aep\Application\Agent\Model\AgentHealth;
use Aep\Application\Agent\Model\AgentSession;
use Aep\Application\Agent\Port\AgentSettingsStore;
use Aep\Application\Agent\Port\AgentStore;
use Aep\Application\MissionControl\Support\Utc;

final class AgentCoordinator
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly AgentSettingsStore $settings,
        private readonly AgentRouter $router,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function assign(array $request): AgentAssignment
    {
        $at = Utc::now();
        $selected = $this->router->select($request);
        $agent = $selected['agent'];
        if (!$agent instanceof Agent) {
            throw new \RuntimeException('No routable agent matched request.');
        }

        $providers = $agent->profile()->preferredProviders();
        $preferred = $providers[0] ?? null;
        $assignment = new AgentAssignment(
            AgentAssignment::makeId(),
            $agent->agentId(),
            is_string($request['role'] ?? null) ? $request['role'] : $agent->role(),
            AgentAssignment::STATUS_RESERVED,
            $at,
            $at,
            is_string($request['programId'] ?? null) ? $request['programId'] : null,
            is_string($request['nodeId'] ?? null) ? $request['nodeId'] : null,
            null,
            null,
            null,
            is_array($request['capabilities'] ?? null) ? array_values(array_filter($request['capabilities'], 'is_string')) : [],
            is_array($request['context'] ?? null) ? $request['context'] : ['selectionScore' => $selected['score']],
            is_string($preferred) ? $preferred : null,
        );
        $this->store->saveAssignment($assignment);

        $agent = $agent
            ->withStatus(Agent::STATUS_RESERVED, $at)
            ->withActiveAssignments($agent->activeAssignments() + 1, $at)
            ->withSealedHashes();
        $this->store->saveAgent($agent);

        $this->store->appendEvent(new AgentEvent(
            AgentEvent::makeId(),
            AgentEvent::ASSIGNMENT_CREATED,
            $at,
            $agent->agentId(),
            $assignment->assignmentId(),
            $assignment->programId(),
            $assignment->nodeId(),
            null,
            ['score' => $selected['score'], 'trace' => $selected['trace']],
        ));

        return $assignment;
    }

    public function start(AgentAssignment $assignment, string $missionId, string $runId): AgentAssignment
    {
        $at = Utc::now();
        $session = new AgentSession(
            AgentSession::makeId(),
            $assignment->assignmentId(),
            $assignment->agentId(),
            'open',
            $at,
            $at,
            $missionId,
            $runId,
        );
        $this->store->saveSession($session);
        $assignment = $assignment->withLaunch($missionId, $runId, $session->sessionId(), $at);
        $this->store->saveAssignment($assignment);

        $agent = $this->store->findAgent($assignment->agentId());
        if ($agent !== null) {
            $this->store->saveAgent($agent->withStatus(Agent::STATUS_WORKING, $at)->withSealedHashes());
        }

        $this->store->appendEvent(new AgentEvent(
            AgentEvent::makeId(),
            AgentEvent::ASSIGNMENT_STARTED,
            $at,
            $assignment->agentId(),
            $assignment->assignmentId(),
            $assignment->programId(),
            $assignment->nodeId(),
            $missionId,
            ['runId' => $runId, 'sessionId' => $session->sessionId()],
        ));

        return $assignment;
    }

    public function complete(string $assignmentId, string $message = ''): AgentAssignment
    {
        $assignment = $this->requireAssignment($assignmentId);
        $at = Utc::now();
        $assignment = $assignment->withStatus(AgentAssignment::STATUS_COMPLETED, $at);
        $this->store->saveAssignment($assignment);
        $this->releaseAgent($assignment->agentId(), true, $at);
        $this->store->appendEvent(new AgentEvent(
            AgentEvent::makeId(),
            AgentEvent::ASSIGNMENT_COMPLETED,
            $at,
            $assignment->agentId(),
            $assignment->assignmentId(),
            $assignment->programId(),
            $assignment->nodeId(),
            $assignment->missionId(),
            ['message' => $message],
        ));

        return $assignment;
    }

    public function fail(string $assignmentId, string $message = ''): AgentAssignment
    {
        $assignment = $this->requireAssignment($assignmentId);
        $at = Utc::now();
        $assignment = $assignment->withStatus(AgentAssignment::STATUS_FAILED, $at);
        $this->store->saveAssignment($assignment);
        $this->releaseAgent($assignment->agentId(), false, $at);
        $this->store->appendEvent(new AgentEvent(
            AgentEvent::makeId(),
            AgentEvent::ASSIGNMENT_FAILED,
            $at,
            $assignment->agentId(),
            $assignment->assignmentId(),
            $assignment->programId(),
            $assignment->nodeId(),
            $assignment->missionId(),
            ['message' => $message],
        ));

        return $assignment;
    }

    public function reassign(string $assignmentId): AgentAssignment
    {
        $old = $this->requireAssignment($assignmentId);
        $at = Utc::now();
        $request = [
            'role' => $old->role(),
            'capabilities' => $old->requiredCapabilities(),
            'programId' => $old->programId(),
            'nodeId' => $old->nodeId(),
            'excludeAgentIds' => [$old->agentId()],
            'context' => ['reassignOf' => $old->assignmentId()],
        ];
        $new = $this->assign($request);
        $old = $old->withSupersededBy($new->assignmentId(), $at);
        $this->store->saveAssignment($old);
        $this->releaseAgent($old->agentId(), false, $at, true);
        $this->store->appendEvent(new AgentEvent(
            AgentEvent::makeId(),
            AgentEvent::ASSIGNMENT_REASSIGNED,
            $at,
            $new->agentId(),
            $new->assignmentId(),
            $old->programId(),
            $old->nodeId(),
            $old->missionId(),
            ['fromAssignmentId' => $old->assignmentId(), 'fromAgentId' => $old->agentId()],
        ));

        return $new;
    }

    public function healthCheck(string $agentId): Agent
    {
        $agent = $this->requireAgent($agentId);
        $at = Utc::now();
        $ok = !in_array($agent->status(), [Agent::STATUS_RETIRED], true);
        $health = $agent->health()->withCheck($ok ? 'ok' : 'failed', $at, $ok ? 'probe ok' : 'retired');
        $agent = $agent->withHealth($health, $at);
        if ($ok && $agent->status() === Agent::STATUS_OFFLINE) {
            $agent = $agent->withStatus(Agent::STATUS_AVAILABLE, $at);
        }
        $agent = $agent->withSealedHashes();
        $this->store->saveAgent($agent);
        if (!$health->isHealthy()) {
            $this->store->appendEvent(new AgentEvent(
                AgentEvent::makeId(),
                AgentEvent::AGENT_UNAVAILABLE,
                $at,
                $agentId,
                null,
                null,
                null,
                null,
                ['detail' => $health->toArray()],
            ));
        }

        return $agent;
    }

    public function retire(string $agentId): Agent
    {
        $agent = $this->requireAgent($agentId)->withStatus(Agent::STATUS_RETIRED, Utc::now())->withSealedHashes();
        $this->store->saveAgent($agent);
        $this->store->appendEvent(new AgentEvent(
            AgentEvent::makeId(),
            AgentEvent::AGENT_RETIRED,
            Utc::now(),
            $agentId,
        ));

        return $agent;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function register(array $input): Agent
    {
        $at = Utc::now();
        $agent = Agent::fromArray([
            'agentId' => is_string($input['agentId'] ?? null) ? $input['agentId'] : Agent::makeId(),
            'name' => is_string($input['name'] ?? null) ? $input['name'] : 'Agent',
            'role' => is_string($input['role'] ?? null) ? $input['role'] : Agent::ROLE_IMPLEMENTER,
            'status' => Agent::STATUS_AVAILABLE,
            'createdAtUtc' => $at,
            'updatedAtUtc' => $at,
            'profile' => is_array($input['profile'] ?? null) ? $input['profile'] : [],
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
            ['role' => $agent->role(), 'name' => $agent->name()],
        ));

        return $agent;
    }

    private function releaseAgent(string $agentId, bool $success, string $atUtc, bool $reassign = false): void
    {
        $agent = $this->store->findAgent($agentId);
        if ($agent === null) {
            return;
        }
        $metrics = $agent->metrics();
        $metrics['successCount'] = (int) ($metrics['successCount'] ?? 0) + ($success ? 1 : 0);
        $metrics['failureCount'] = (int) ($metrics['failureCount'] ?? 0) + ($success ? 0 : 1);
        $metrics['reassignmentCount'] = (int) ($metrics['reassignmentCount'] ?? 0) + ($reassign ? 1 : 0);
        $active = max(0, $agent->activeAssignments() - 1);
        $status = $active > 0 ? Agent::STATUS_WORKING : Agent::STATUS_AVAILABLE;
        $this->store->saveAgent(
            $agent->withActiveAssignments($active, $atUtc)
                ->withStatus($status, $atUtc)
                ->withMetrics($metrics, $atUtc)
                ->withSealedHashes()
        );
    }

    private function requireAssignment(string $id): AgentAssignment
    {
        $a = $this->store->findAssignment($id);
        if ($a === null) {
            throw new \InvalidArgumentException('Unknown assignment: ' . $id);
        }

        return $a;
    }

    private function requireAgent(string $id): Agent
    {
        $a = $this->store->findAgent($id);
        if ($a === null) {
            throw new \InvalidArgumentException('Unknown agent: ' . $id);
        }

        return $a;
    }
}
