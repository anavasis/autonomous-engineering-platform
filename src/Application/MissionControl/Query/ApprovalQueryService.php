<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Query;

use Aep\Application\MissionControl\Catalog\MissionCatalog;
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

            if ($state === MissionState::AWAITING_INSPECTION_APPROVAL) {
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

            if ($state === MissionState::AWAITING_COMMIT_APPROVAL) {
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
                $gateId = $this->pendingGateId($run->attributes(), $run->message(), $run->planStepIds()[$run->cursorIndex()] ?? null);
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
