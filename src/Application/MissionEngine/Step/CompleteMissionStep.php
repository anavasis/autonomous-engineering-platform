<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;
use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\ValueObject\ApprovalRecord;
use Aep\Domain\Mission\ValueObject\MissionState;

final class CompleteMissionStep implements MissionStep
{
    public function id(): string
    {
        return 'complete_mission';
    }

    public function name(): string
    {
        return 'Complete mission';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $error = $this->missingEvidence($context);
            if ($error !== null) {
                return StepResult::failed($error);
            }

            $context->missions()->complete(new TimedMissionCommand(
                $context->missionId(),
                $context->occurredAtUtc()
            ));

            return StepResult::succeeded('Mission completed with verified patch artifact.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }

    private function missingEvidence(MissionContext $context): ?string
    {
        $providerId = $context->attribute('providerId');
        if ((!is_string($providerId) || trim($providerId) === '')
            && (!is_string($context->attribute('routedProviderId')) || trim((string) $context->attribute('routedProviderId')) === '')
        ) {
            return 'Cannot complete mission: providerId is required.';
        }
        $sessionId = $context->attribute('sessionId');
        if (!is_string($sessionId) || trim($sessionId) === '') {
            return 'Cannot complete mission: sessionId is required.';
        }
        $workspacePath = $context->attribute('workspacePath');
        if (!is_string($workspacePath) || trim($workspacePath) === '') {
            return 'Cannot complete mission: workspacePath is required.';
        }
        $files = $context->attribute('filesChanged');
        if (!is_array($files) || $files === []) {
            return 'Cannot complete mission: filesChanged must be non-empty.';
        }
        $patchId = $context->attribute('patchId');
        if (!is_string($patchId) || trim($patchId) === '') {
            return 'Cannot complete mission: patchId is required.';
        }
        $patchStatus = $context->attribute('patchStatus');
        if (!is_string($patchStatus) || trim($patchStatus) === '') {
            return 'Cannot complete mission: patchStatus is required.';
        }
        if ($context->attribute('mergeReady') !== true) {
            return 'Cannot complete mission: mergeReady must be true.';
        }
        $outcome = $context->attribute('validationOutcome');
        if (!is_string($outcome) || strtolower(trim($outcome)) !== 'passed') {
            return 'Cannot complete mission: successful validation evidence is required.';
        }

        $mission = $this->loadMission($context);
        if ($mission === null) {
            return 'Cannot complete mission: unable to load mission for commit approval evidence.';
        }
        if ($mission->state()->toString() !== MissionState::PR_READY) {
            return 'Cannot complete mission: mission must be in pr_ready state.';
        }
        $approval = $mission->commitApproval();
        if ($approval === null
            || $approval->subject() !== ApprovalRecord::SUBJECT_COMMIT
            || !$approval->isApproved()
        ) {
            return 'Cannot complete mission: approved commit gate is required.';
        }

        return null;
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
