<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Service;

use Aep\Application\Acceptance\Model\AcceptanceContext;
use Aep\Application\Acceptance\Model\AcceptanceProject;
use Aep\Application\Acceptance\Model\ValidationOutcome;
use Aep\Application\Acceptance\Model\ValidationRule;

/**
 * Evaluates data-driven validation rules against an AcceptanceContext.
 */
final class AcceptanceValidator
{
    /**
     * @return list<ValidationOutcome>
     */
    public function validate(AcceptanceProject $project, AcceptanceContext $context): array
    {
        $outcomes = [];
        foreach ($project->validationRules() as $rule) {
            $outcomes[] = $this->evaluate($rule, $project, $context);
        }

        return $outcomes;
    }

    public function evaluate(
        ValidationRule $rule,
        AcceptanceProject $project,
        AcceptanceContext $context,
    ): ValidationOutcome {
        return match ($rule->type()) {
            ValidationRule::TYPE_PROJECT_STARTED => $this->projectStarted($rule, $context),
            ValidationRule::TYPE_PROJECT_COMPLETED => $this->projectCompleted($rule, $context),
            ValidationRule::TYPE_EXECUTION_SUCCESSFUL => $this->executionSuccessful($rule, $context),
            ValidationRule::TYPE_RUNTIME_STATUS => $this->runtimeStatus($rule, $context),
            ValidationRule::TYPE_ARTIFACT_GENERATED => $this->artifactGenerated($rule, $project, $context),
            ValidationRule::TYPE_FILE_EXISTS => $this->fileExists($rule, $context),
            ValidationRule::TYPE_DIRECTORY_EXISTS => $this->directoryExists($rule, $context),
            ValidationRule::TYPE_TESTS_EXECUTED => $this->signalTrue($rule, $context, 'tests_executed', 'Tests executed'),
            ValidationRule::TYPE_BUILD_COMPLETED => $this->signalTrue($rule, $context, 'build_completed', 'Build completed'),
            ValidationRule::TYPE_EXECUTION_DURATION => $this->executionDuration($rule, $context),
            ValidationRule::TYPE_EXPECTED_ARTIFACT => $this->expectedArtifact($rule, $context),
            default => new ValidationOutcome(
                $rule->id(),
                $rule->type(),
                false,
                'Unknown validation rule type: ' . $rule->type(),
            ),
        };
    }

    private function projectStarted(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $ok = $context->projectStarted()
            && $context->missionId() !== null
            && $context->missionId() !== ''
            && $context->runId() !== null
            && $context->runId() !== '';

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok ? 'Project execution started.' : 'Project did not start (missing mission/run).',
            [
                'missionId' => $context->missionId(),
                'runId' => $context->runId(),
                'jobId' => $context->jobId(),
            ],
        );
    }

    private function projectCompleted(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $accepted = $rule->params()['acceptedStates'] ?? ['completed'];
        if (!is_array($accepted) || $accepted === []) {
            $accepted = ['completed'];
        }
        $state = $context->engineState();
        $ok = $state !== null && in_array($state, $accepted, true);

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok
                ? 'Project completed with engine state: ' . $state
                : 'Project not completed. Engine state: ' . ($state ?? 'null'),
            ['engineState' => $state, 'acceptedStates' => $accepted],
        );
    }

    private function executionSuccessful(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $runtimeOk = in_array($context->runtimeStatus(), ['completed', null], true)
            && $context->runtimeStatus() !== 'failed'
            && $context->runtimeStatus() !== 'cancelled';
        // Prefer explicit completed runtime when a job was created.
        if ($context->jobId() !== null && $context->jobId() !== '') {
            $runtimeOk = $context->runtimeStatus() === 'completed';
        }
        $engineFailed = in_array($context->engineState(), ['failed', 'timed_out'], true);
        $ok = $context->projectStarted() && $runtimeOk && !$engineFailed;

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok ? 'Execution successful.' : 'Execution not successful.',
            [
                'runtimeStatus' => $context->runtimeStatus(),
                'engineState' => $context->engineState(),
            ],
        );
    }

    private function runtimeStatus(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $accepted = $rule->params()['accepted'] ?? ['completed'];
        if (!is_array($accepted)) {
            $accepted = ['completed'];
        }
        $status = $context->runtimeStatus();
        $ok = $status !== null && in_array($status, $accepted, true);

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok ? 'Runtime status acceptable: ' . $status : 'Runtime status not acceptable: ' . ($status ?? 'null'),
            ['runtimeStatus' => $status, 'accepted' => $accepted],
        );
    }

    private function artifactGenerated(
        ValidationRule $rule,
        AcceptanceProject $project,
        AcceptanceContext $context,
    ): ValidationOutcome {
        $min = is_int($rule->params()['minCount'] ?? null) ? $rule->params()['minCount'] : 1;
        $count = count($context->artifacts());
        $ok = $count >= $min;

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok
                ? "Artifacts generated: {$count} (min {$min})."
                : "Insufficient artifacts: {$count} (min {$min}).",
            [
                'count' => $count,
                'minCount' => $min,
                'expectedArtifacts' => $project->expectedArtifacts(),
            ],
        );
    }

    private function fileExists(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $path = is_string($rule->params()['path'] ?? null) ? $rule->params()['path'] : '';
        if ($path === '') {
            return new ValidationOutcome($rule->id(), $rule->type(), false, 'file_exists rule missing path.');
        }
        $found = $this->resolvePath($path, $context->workspaceRoots());
        $ok = $found !== null && is_file($found);

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok ? 'Required file exists: ' . $path : 'Required file missing: ' . $path,
            ['path' => $path, 'resolved' => $found],
        );
    }

    private function directoryExists(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $path = is_string($rule->params()['path'] ?? null) ? $rule->params()['path'] : '';
        if ($path === '') {
            return new ValidationOutcome($rule->id(), $rule->type(), false, 'directory_exists rule missing path.');
        }
        $found = $this->resolvePath($path, $context->workspaceRoots());
        $ok = $found !== null && is_dir($found);

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok ? 'Required directory exists: ' . $path : 'Required directory missing: ' . $path,
            ['path' => $path, 'resolved' => $found],
        );
    }

    private function signalTrue(
        ValidationRule $rule,
        AcceptanceContext $context,
        string $key,
        string $label,
    ): ValidationOutcome {
        $paramKey = is_string($rule->params()['signal'] ?? null) ? $rule->params()['signal'] : $key;
        $value = $context->signal($paramKey);
        $ok = $value === true || $value === 1 || $value === 'true' || $value === 'passed' || $value === 'ok';

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok ? $label . '.' : $label . ' signal not observed.',
            ['signal' => $paramKey, 'value' => $value],
        );
    }

    private function executionDuration(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $max = is_int($rule->params()['maxSeconds'] ?? null)
            ? $rule->params()['maxSeconds']
            : (is_float($rule->params()['maxSeconds'] ?? null) ? (int) $rule->params()['maxSeconds'] : 3600);
        $min = is_int($rule->params()['minSeconds'] ?? null) ? $rule->params()['minSeconds'] : 0;
        $elapsed = $context->executionTimeSeconds();
        $ok = $elapsed >= $min && $elapsed <= $max;

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            $ok,
            $ok
                ? sprintf('Execution duration %.2fs within bounds [%d, %d].', $elapsed, $min, $max)
                : sprintf('Execution duration %.2fs outside bounds [%d, %d].', $elapsed, $min, $max),
            ['elapsedSeconds' => $elapsed, 'minSeconds' => $min, 'maxSeconds' => $max],
        );
    }

    private function expectedArtifact(ValidationRule $rule, AcceptanceContext $context): ValidationOutcome
    {
        $name = is_string($rule->params()['name'] ?? null) ? $rule->params()['name'] : '';
        if ($name === '') {
            return new ValidationOutcome($rule->id(), $rule->type(), false, 'expected_artifact missing name.');
        }
        foreach ($context->artifacts() as $artifact) {
            $artName = is_string($artifact['name'] ?? null) ? $artifact['name'] : '';
            if ($artName === $name || str_ends_with($artName, $name)) {
                return new ValidationOutcome(
                    $rule->id(),
                    $rule->type(),
                    true,
                    'Expected artifact present: ' . $name,
                    ['artifact' => $artifact],
                );
            }
        }
        // Also accept file presence under workspace roots.
        $file = $this->resolvePath($name, $context->workspaceRoots());
        if ($file !== null && is_file($file)) {
            return new ValidationOutcome(
                $rule->id(),
                $rule->type(),
                true,
                'Expected artifact present on disk: ' . $name,
                ['resolved' => $file],
            );
        }

        return new ValidationOutcome(
            $rule->id(),
            $rule->type(),
            false,
            'Expected artifact missing: ' . $name,
        );
    }

    /**
     * @param list<string> $roots
     */
    private function resolvePath(string $relative, array $roots): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '' || !is_dir($root)) {
                continue;
            }
            $candidate = rtrim($root, "/\\") . '/' . $relative;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
