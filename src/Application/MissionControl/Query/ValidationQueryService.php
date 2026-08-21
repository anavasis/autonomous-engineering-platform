<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\Artifact\ArtifactKind;
use Aep\Application\Artifact\ArtifactService;
use Aep\Application\Artifact\WorkspaceManager;
use Aep\Application\MissionControl\Catalog\MissionCatalog;

final class ValidationQueryService
{
    public function __construct(
        private readonly MissionCatalog $missions,
        private readonly MissionQueryService $missionQuery,
        private readonly WorkspaceManager $workspaces,
        private readonly ArtifactService $artifacts,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->missions->all() as $mission) {
            $detail = $this->forMission($mission->id()->toString());
            if ($detail !== null) {
                $rows[] = $detail;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forMission(string $missionId): ?array
    {
        $mission = $this->missions->find($missionId);
        if ($mission === null) {
            return null;
        }

        $domain = $mission->lastValidationResult();
        $artifactView = $this->latestValidationArtifact($missionId);

        if ($domain === null && $artifactView === null) {
            return [
                'missionId' => $missionId,
                'outcome' => 'unknown',
                'passed' => 0,
                'failed' => 0,
                'warnings' => 0,
                'durationSeconds' => 0,
                'reason' => 'No validation recorded yet.',
                'steps' => [],
            ];
        }

        $steps = $artifactView['steps'] ?? [];
        $passed = 0;
        $failed = 0;
        $warnings = 0;
        foreach ($steps as $step) {
            $status = is_array($step) ? (string) ($step['status'] ?? $step['outcome'] ?? '') : '';
            if ($status === 'passed' || $status === 'ok') {
                $passed++;
            } elseif ($status === 'failed' || $status === 'error') {
                $failed++;
            } elseif ($status === 'warning' || $status === 'warn') {
                $warnings++;
            }
        }

        $outcome = $domain?->outcome() ?? ($artifactView['outcome'] ?? 'unknown');
        if ($domain !== null) {
            if ($domain->outcome() === 'passed') {
                $passed = max($passed, 1);
            } else {
                $failed = max($failed, 1);
            }
        }

        return [
            'missionId' => $missionId,
            'objective' => $mission->brief()->objective(),
            'outcome' => $outcome,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
            'durationSeconds' => (int) ($artifactView['durationSeconds'] ?? 0),
            'reason' => $domain?->reason() ?? ($artifactView['reason'] ?? ''),
            'steps' => $steps,
            'updatedAtUtc' => $artifactView['updatedAtUtc'] ?? $mission->createdAtUtc(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestValidationArtifact(string $missionId): ?array
    {
        $candidates = [];
        foreach ($this->workspaces->listWorkspaces() as $workspace) {
            if ($workspace->missionId() !== $missionId) {
                continue;
            }
            try {
                foreach ($this->artifacts->list($workspace->workspaceId(), ArtifactKind::VALIDATION) as $artifact) {
                    $raw = $this->artifacts->readContents($workspace->workspaceId(), $artifact->artifactId());
                    $decoded = json_decode($raw, true);
                    $steps = [];
                    $outcome = 'unknown';
                    $reason = '';
                    $duration = 0;
                    if (is_array($decoded)) {
                        $outcome = is_string($decoded['outcome'] ?? null) ? $decoded['outcome'] : $outcome;
                        $reason = is_string($decoded['reason'] ?? null) ? $decoded['reason'] : $reason;
                        $duration = is_numeric($decoded['durationSeconds'] ?? null) ? (int) $decoded['durationSeconds'] : 0;
                        if (isset($decoded['outcomes']) && is_array($decoded['outcomes'])) {
                            $steps = $decoded['outcomes'];
                        } elseif (isset($decoded['steps']) && is_array($decoded['steps'])) {
                            $steps = $decoded['steps'];
                        }
                    }
                    $candidates[] = [
                        'outcome' => $outcome,
                        'reason' => $reason,
                        'durationSeconds' => $duration,
                        'steps' => $steps,
                        'updatedAtUtc' => $artifact->createdAtUtc(),
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp((string) $b['updatedAtUtc'], (string) $a['updatedAtUtc'])
        );

        return $candidates[0];
    }
}
