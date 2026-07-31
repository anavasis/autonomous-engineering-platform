<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Support;

use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionTimeline;
use Aep\Domain\Mission\Mission;

final class MissionSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Mission $mission, ?MissionCheckpoint $run = null): array
    {
        $plan = $run?->planStepIds() ?? [];
        $cursor = $run?->cursorIndex() ?? 0;
        $currentStep = $plan[$cursor] ?? null;

        return [
            'id' => $mission->id()->toString(),
            'state' => $mission->state()->toString(),
            'objective' => $mission->brief()->objective(),
            'target' => [
                'provider' => $mission->target()->provider(),
                'repository' => $mission->target()->repository(),
            ],
            'createdAtUtc' => $mission->createdAtUtc(),
            'createdBy' => [
                'type' => $mission->createdBy()->type(),
                'id' => $mission->createdBy()->id(),
            ],
            'assignedAgent' => null,
            'latestRun' => $run === null ? null : self::runSummary($run, $currentStep),
            'progress' => $run?->progressPercent() ?? 0,
            'currentStep' => $currentStep,
            'workflow' => is_string($run?->attributes()['workflowId'] ?? null)
                ? $run->attributes()['workflowId']
                : null,
            'durationSeconds' => self::durationSeconds($mission->createdAtUtc(), $run?->updatedAtUtc()),
            'projectId' => $run?->projectId(),
            'validation' => $mission->lastValidationResult() === null ? null : [
                'outcome' => $mission->lastValidationResult()->outcome(),
                'reason' => $mission->lastValidationResult()->reason(),
            ],
            'awaitingInspectionApproval' => $mission->state()->is('awaiting_inspection_approval'),
            'awaitingCommitApproval' => $mission->state()->is('awaiting_commit_approval'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(
        Mission $mission,
        ?MissionCheckpoint $run = null,
        ?MissionTimeline $timeline = null
    ): array {
        $base = self::summary($mission, $run);
        $base['scope'] = $mission->scopePolicy() === null ? null : [
            'allowedPaths' => $mission->scopePolicy()->allowedPaths(),
            'nonGoals' => $mission->scopePolicy()->nonGoals(),
        ];
        $base['inspectionFindings'] = $mission->inspectionFindings() === null ? null : [
            'summary' => $mission->inspectionFindings()->summary(),
            'status' => $mission->inspectionFindings()->status(),
        ];
        $base['inspectionApproval'] = self::approval($mission->inspectionApproval());
        $base['commitApproval'] = self::approval($mission->commitApproval());
        $base['timeline'] = $timeline === null
            ? []
            : array_map(static fn ($e) => $e->toArray(), $timeline->entries());
        $base['checkpoint'] = $run?->toArray();

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function runSummary(MissionCheckpoint $run, ?string $currentStep = null): array
    {
        $plan = $run->planStepIds();
        $step = $currentStep ?? ($plan[$run->cursorIndex()] ?? null);

        return [
            'runId' => $run->runId(),
            'missionId' => $run->missionId(),
            'projectId' => $run->projectId(),
            'engineState' => $run->engineState(),
            'progressPercent' => $run->progressPercent(),
            'currentStep' => $step,
            'planStepIds' => $plan,
            'updatedAtUtc' => $run->updatedAtUtc(),
            'message' => $run->message(),
            'assignedAgent' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function approval(mixed $approval): ?array
    {
        if ($approval === null) {
            return null;
        }

        return [
            'subject' => $approval->subject(),
            'decision' => $approval->decision(),
            'actor' => [
                'type' => $approval->actor()->type(),
                'id' => $approval->actor()->id(),
            ],
            'occurredAtUtc' => $approval->occurredAtUtc(),
            'rationale' => $approval->rationale(),
        ];
    }

    private static function durationSeconds(string $createdAtUtc, ?string $updatedAtUtc): int
    {
        $start = strtotime($createdAtUtc);
        $end = strtotime($updatedAtUtc ?? $createdAtUtc);
        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, $end - $start);
    }
}
