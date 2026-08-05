<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\MissionControl\Catalog\MissionCatalog;
use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Domain\Mission\ValueObject\MissionState;

final class ApprovalQueryService
{
    public function __construct(
        private readonly MissionCatalog $missions,
        private readonly MissionQueryService $missionQuery,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $queue = [];
        foreach ($this->missions->all() as $mission) {
            $id = $mission->id()->toString();
            $run = $this->missionQuery->latestRun($id);
            $state = $mission->state()->toString();
            $waitingInspectionGate = $this->isWaitingOnGate($run, 'inspection');
            $waitingCommitGate = $this->isWaitingOnGate($run, 'commit');

            // When the engine is paused on ManualGateStep('inspection'), the gate row is
            // the single actionable approval. Do not also emit kind=inspection.
            if ($state === MissionState::AWAITING_INSPECTION_APPROVAL && !$waitingInspectionGate) {
                $queue[] = [
                    'missionId' => $id,
                    'kind' => 'inspection',
                    'subject' => 'inspection',
                    'objective' => $mission->brief()->objective(),
                    'state' => $state,
                    'runId' => $run?->runId(),
                    'updatedAtUtc' => $run?->updatedAtUtc() ?? $mission->createdAtUtc(),
                    'message' => 'Inspection approval required.',
                ];
            }

            // When waiting on ManualGateStep('commit'), emit only the gate row.
            if ($state === MissionState::AWAITING_COMMIT_APPROVAL && !$waitingCommitGate) {
                $queue[] = [
                    'missionId' => $id,
                    'kind' => 'merge',
                    'subject' => 'commit',
                    'objective' => $mission->brief()->objective(),
                    'state' => $state,
                    'runId' => $run?->runId(),
                    'updatedAtUtc' => $run?->updatedAtUtc() ?? $mission->createdAtUtc(),
                    'message' => 'Merge (commit) approval required.',
                ];
            }

            if ($run !== null && $run->engineState() === MissionRunState::WAITING) {
                $gateId = $this->pendingGateId(
                    $run->attributes(),
                    $run->message(),
                    $run->planStepIds()[$run->cursorIndex()] ?? null
                );
                $queue[] = [
                    'missionId' => $id,
                    'kind' => 'implementation_gate',
                    'subject' => 'gate',
                    'gateId' => $gateId,
                    'objective' => $mission->brief()->objective(),
                    'state' => $state,
                    'runId' => $run->runId(),
                    'updatedAtUtc' => $run->updatedAtUtc(),
                    'message' => $run->message() !== '' ? $run->message() : 'Manual gate pending.',
                ];
            }
        }

        usort(
            $queue,
            static fn (array $a, array $b): int => strcmp((string) $b['updatedAtUtc'], (string) $a['updatedAtUtc'])
        );

        return $queue;
    }

    private function isWaitingOnGate(?MissionCheckpoint $run, string $expectedGateId): bool
    {
        if ($run === null || $run->engineState() !== MissionRunState::WAITING) {
            return false;
        }
        $gateId = $this->pendingGateId(
            $run->attributes(),
            $run->message(),
            $run->planStepIds()[$run->cursorIndex()] ?? null
        );

        return $gateId === $expectedGateId;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function pendingGateId(array $attributes, string $message, ?string $currentStep): ?string
    {
        if (isset($attributes['gate']) && is_string($attributes['gate']) && $attributes['gate'] !== '') {
            return $attributes['gate'];
        }
        if (preg_match('/Manual gate pending:\s*(.+)$/', $message, $matches) === 1) {
            return trim($matches[1]);
        }
        if ($currentStep !== null && $currentStep !== '') {
            return $currentStep;
        }

        return null;
    }
}
