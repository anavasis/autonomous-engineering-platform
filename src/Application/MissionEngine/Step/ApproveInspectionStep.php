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

final class ApproveInspectionStep implements MissionStep
{
    public function id(): string
    {
        return 'approve_inspection';
    }

    public function name(): string
    {
        return 'Approve inspection';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $mission = $this->loadMission($context);
            if ($mission === null) {
                return StepResult::failed('Unable to load mission for inspection approval.');
            }

            $state = $mission->state()->toString();

            if ($state === MissionState::AWAITING_INSPECTION_APPROVAL) {
                $context->missions()->approveInspection(new ApprovalCommand(
                    $context->missionId(),
                    $context->actorType(),
                    $context->actorId(),
                    $context->occurredAtUtc(),
                    'engine gate approved'
                ));

                return StepResult::succeeded('Inspection approved.');
            }

            if ($state === MissionState::IMPLEMENTING && $this->hasApprovedInspection($mission)) {
                // Compatibility: domain approval already recorded (e.g. decideInspection).
                return StepResult::succeeded('Inspection already approved.');
            }

            return StepResult::failed(
                'Cannot approve inspection from mission state ' . $state
                . ($state === MissionState::IMPLEMENTING
                    ? ' without an approved inspection record.'
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

    private function hasApprovedInspection(Mission $mission): bool
    {
        $approval = $mission->inspectionApproval();
        if ($approval === null) {
            return false;
        }

        return $approval->subject() === ApprovalRecord::SUBJECT_INSPECTION
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
