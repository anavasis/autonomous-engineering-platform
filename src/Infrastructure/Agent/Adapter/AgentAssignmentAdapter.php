<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Agent\Adapter;

use Aep\Application\Agent\Port\AgentSettingsStore;
use Aep\Application\Agent\Service\AgentCoordinator;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;

/**
 * Decorates PlanningLaunchPort: assigns a policy-selected agent around launch.
 * Does not alter Planning scheduler semantics or Mission Engine contracts.
 */
final class AgentAssignmentAdapter implements PlanningLaunchPort
{
    public function __construct(
        private readonly PlanningLaunchPort $inner,
        private readonly AgentCoordinator $coordinator,
        private readonly AgentSettingsStore $settings,
    ) {
    }

    public function launchNode(Program $program, ProgramNode $node, string $actorId): array
    {
        $settings = $this->settings->get();
        if (($settings['enabled'] ?? true) !== true || ($settings['assignOnPlanningLaunch'] ?? true) !== true) {
            return $this->inner->launchNode($program, $node, $actorId);
        }

        $role = $this->inferRole($node);
        $capabilities = $this->inferCapabilities($node, $role);
        $assignment = null;
        try {
            $assignment = $this->coordinator->assign([
                'role' => $role,
                'capabilities' => $capabilities,
                'programId' => $program->programId(),
                'nodeId' => $node->nodeId(),
                'context' => [
                    'objective' => $node->objective(),
                    'title' => $node->title(),
                    'actorId' => $actorId,
                ],
            ]);
        } catch (\Throwable) {
            // best-effort: launch even if no agent available
        }

        $result = $this->inner->launchNode($program, $node, $actorId);

        if ($assignment !== null) {
            try {
                $this->coordinator->start($assignment, $result['missionId'], $result['runId']);
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    private function inferRole(ProgramNode $node): string
    {
        $constraints = $node->constraints();
        if (is_string($constraints['agentRole'] ?? null) && $constraints['agentRole'] !== '') {
            return $constraints['agentRole'];
        }
        $hay = strtolower($node->title() . ' ' . $node->objective());
        $has = static fn (string $needle): bool => (bool) preg_match('/\b' . preg_quote($needle, '/') . '\b/', $hay);

        return match (true) {
            $has('inspect') || $has('scope') || $has('architect') => 'architect',
            $has('review') || $has('reviewer') => 'reviewer',
            $has('validate') || $has('validation') || $has('testing') || $has('qa') || $has('seal') => 'qa',
            $has('documentation') || $has('docs') || $has('document') => 'documentation',
            $has('security') || $has('secure') => 'security',
            $has('performance') || $has('perf') => 'performance',
            $has('plan') || $has('planner') => 'planner',
            default => 'implementer',
        };
    }

    /** @return list<string> */
    private function inferCapabilities(ProgramNode $node, string $role): array
    {
        $constraints = $node->constraints();
        if (isset($constraints['requiredCapabilities']) && is_array($constraints['requiredCapabilities'])) {
            $out = [];
            foreach ($constraints['requiredCapabilities'] as $c) {
                if (is_string($c)) {
                    $out[] = $c;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        return match ($role) {
            'architect' => ['architecture'],
            'reviewer' => ['testing'],
            'qa' => ['testing'],
            'documentation' => ['documentation'],
            'security' => ['security'],
            'performance' => ['performance'],
            'planner' => ['architecture'],
            default => ['php'],
        };
    }
}
