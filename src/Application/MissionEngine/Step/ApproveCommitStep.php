<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;
use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\MissionState;

final class ApproveCommitStep implements MissionStep
{
    public function id(): string
    {
        return 'approve_commit';
    }

    public function name(): string
    {
        return 'Approve commit';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $mission = $this->loadMission($context);
            if ($mission === null) {
                return StepResult::failed('Unable to load mission for commit approval.');
            }

            $state = $mission->state()->toString();

            if ($state === MissionState::AWAITING_COMMIT_APPROVAL) {
                $context->missions()->approveCommit(new ApprovalCommand(
                    $context->missionId(),
                    $context->actorType(),
                    $context->actorId(),
                    $context->occurredAtUtc(),
                    'engine gate approved'
                ));

                return StepResult::succeeded('Commit approved.');
            }

            if ($state === MissionState::COMMITTING && $this->hasApprovedCommit($mission)) {
                return StepResult::succeeded('Commit already approved.');
            }

            return StepResult::failed(
                'Cannot approve commit from mission state ' . $state
                . ($state === MissionState::COMMITTING
                    ? ' without an approved commit record.'
                    : '.')
            );
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\DomainException $e) {
            return StepResult::failed($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }

    private function hasApprovedCommit(Mission $mission): bool
    {
        $approval = $mission->commitApproval();
        if ($approval === null) {
            return false;
        }

        return $approval->subject() === ApprovalRecord::SUBJECT_COMMIT
            && $approval->isApproved();
    }

    private function loadMission(MissionContext $context): ?Mission
    {
        try {
            $missions = $context->missions();
            $load = \Closure::bind(
                function (string $missionId): Mission {
                    return $this->load($missionId);
                },
                $missions,
                MissionCommandService::class
            );
            if (!is_callable($load)) {
                return null;
            }

            return $load($context->missionId());
        } catch (\Throwable) {
            return null;
        }
    }
}
