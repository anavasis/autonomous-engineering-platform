<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class MarkPrReadyStep implements MissionStep
{
    public function id(): string
    {
        return 'mark_pr_ready';
    }

    public function name(): string
    {
        return 'Mark PR ready';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $error = $this->missingEvidence($context);
            if ($error !== null) {
                return StepResult::failed($error);
            }

            $context->missions()->markPrReady(new TimedMissionCommand(
                $context->missionId(),
                $context->occurredAtUtc()
            ));

            $prUrl = $context->attribute('prUrl');
            $prNumber = $context->attribute('prNumber');
            if (is_string($prUrl) && trim($prUrl) !== '') {
                $msg = 'PR reference recorded: ' . trim($prUrl);
                if (is_int($prNumber) || (is_string($prNumber) && trim($prNumber) !== '')) {
                    $msg .= ' (#' . trim((string) $prNumber) . ')';
                }

                return StepResult::succeeded($msg);
            }

            return StepResult::succeeded('Patch is ready for PR creation.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }

    private function missingEvidence(MissionContext $context): ?string
    {
        $patchId = $context->attribute('patchId');
        if (!is_string($patchId) || trim($patchId) === '') {
            return 'Cannot mark patch ready: patchId is required.';
        }
        $patchStatus = $context->attribute('patchStatus');
        if (!is_string($patchStatus) || trim($patchStatus) === '') {
            return 'Cannot mark patch ready: patchStatus is required.';
        }
        if ($context->attribute('mergeReady') !== true) {
            return 'Cannot mark patch ready: mergeReady must be true.';
        }
        $files = $context->attribute('filesChanged');
        if (!is_array($files) || $files === []) {
            return 'Cannot mark patch ready: filesChanged must be non-empty.';
        }
        $outcome = $context->attribute('validationOutcome');
        if (!is_string($outcome) || strtolower(trim($outcome)) !== 'passed') {
            return 'Cannot mark patch ready: successful validation evidence is required.';
        }

        return null;
    }
}
