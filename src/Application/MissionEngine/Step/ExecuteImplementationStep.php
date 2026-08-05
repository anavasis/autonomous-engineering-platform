<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;
use Aep\Domain\Mission\Mission;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;

final class ExecuteImplementationStep implements MissionStep
{
    public function id(): string
    {
        return 'execute_implementation';
    }

    public function name(): string
    {
        return 'Execute implementation';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            $action = $context->attribute('executionAction', 'implement');
            if (!is_string($action) || trim($action) === '') {
                $action = 'implement';
            }

            $result = $context->requireExecution()->execute(new ExecutionRequest(
                $context->missionId(),
                $action,
                $context->occurredAtUtc(),
                $this->buildExecutionContext($context)
            ));

            if ($result->isSucceeded()) {
                $evidenceError = $this->missingEvidence($result);
                if ($evidenceError !== null) {
                    return StepResult::failed($evidenceError, false);
                }

                return StepResult::succeeded(
                    $result->message() !== '' ? $result->message() : 'Implementation executed.',
                    $this->whitelistedContext($result)
                );
            }
            if ($result->isRejected()) {
                return StepResult::rejected($result->message());
            }

            // Executor failures are retryable by default for MVP.
            return StepResult::failed($result->message(), true);
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage(), true);
        }
    }

    private function missingEvidence(ExecutionResult $result): ?string
    {
        $ctx = $result->context();

        if (($ctx['legacyBypass'] ?? false) === true) {
            return 'Implementation success rejected: legacyBypass is not allowed for real execution.';
        }

        $actual = $ctx['actualExecutorId'] ?? $result->executorId();
        if (is_string($actual) && trim($actual) === DeclarativeLocalExecutor::ID) {
            return 'Implementation success rejected: declarative_local executor is not allowed.';
        }

        $providerId = $this->firstNonEmptyString([
            $ctx['routedProviderId'] ?? null,
            $ctx['providerId'] ?? null,
        ]);
        if ($providerId === null) {
            return 'Implementation success rejected: providerId is required.';
        }

        $sessionId = $this->firstNonEmptyString([$ctx['sessionId'] ?? null]);
        if ($sessionId === null) {
            return 'Implementation success rejected: sessionId is required.';
        }

        $workspacePath = $this->firstNonEmptyString([$ctx['workspacePath'] ?? null]);
        if ($workspacePath === null) {
            return 'Implementation success rejected: workspacePath is required.';
        }

        $filesChanged = $ctx['filesChanged'] ?? null;
        if (!is_array($filesChanged) || $filesChanged === []) {
            return 'Implementation success rejected: filesChanged must be a non-empty list.';
        }
        $hasFile = false;
        foreach ($filesChanged as $path) {
            if (is_string($path) && trim($path) !== '') {
                $hasFile = true;
                break;
            }
        }
        if (!$hasFile) {
            return 'Implementation success rejected: filesChanged must contain at least one path.';
        }

        $patchId = $this->firstNonEmptyString([$ctx['patchId'] ?? null]);
        if ($patchId === null) {
            return 'Implementation success rejected: patchId is required.';
        }

        $patchStatus = $this->firstNonEmptyString([$ctx['patchStatus'] ?? null]);
        if ($patchStatus === null) {
            return 'Implementation success rejected: patchStatus is required.';
        }

        $artifacts = $ctx['artifacts'] ?? null;
        if (!is_array($artifacts) || $artifacts === []) {
            return 'Implementation success rejected: artifacts are required.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function whitelistedContext(ExecutionResult $result): array
    {
        $ctx = $result->context();
        $out = [];

        foreach ([
            'providerId',
            'routedProviderId',
            'actualExecutorId',
            'sessionId',
            'workspacePath',
            'filesChanged',
            'artifacts',
            'usage',
            'checkpointId',
            'patchId',
            'patchStatus',
            'mergeReady',
        ] as $key) {
            if (array_key_exists($key, $ctx)) {
                $out[$key] = $ctx[$key];
            }
        }

        if (!isset($out['actualExecutorId'])) {
            $out['actualExecutorId'] = $result->executorId();
        }

        return $out;
    }

    /**
     * Build the EngineeringExecution context schema consumed by EngineeringWorkspaceService.
     *
     * @return array<string, mixed>
     */
    private function buildExecutionContext(MissionContext $context): array
    {
        $executionContext = [
            'runId' => $context->runId(),
            'missionId' => $context->missionId(),
        ];

        $projectId = $context->projectId();
        if (is_string($projectId) && trim($projectId) !== '') {
            $executionContext['projectId'] = trim($projectId);
        }

        $providerId = $context->attribute('providerId');
        if (is_string($providerId) && trim($providerId) !== '') {
            $executionContext['providerId'] = trim($providerId);
        }

        $allowedPaths = $this->resolveAllowedPaths($context);
        if ($allowedPaths !== []) {
            $executionContext['allowedPaths'] = $allowedPaths;
        }

        $git = $this->resolveGitSpec($context);
        if ($git !== []) {
            $executionContext['git'] = $git;
        }

        return $executionContext;
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedPaths(MissionContext $context): array
    {
        $fromAttr = $context->attribute('allowedPaths');
        $paths = $this->stringList($fromAttr);
        if ($paths !== []) {
            return $paths;
        }

        $mission = $this->tryLoadMission($context);
        $scope = $mission?->scopePolicy();
        if ($scope !== null) {
            return $this->stringList($scope->allowedPaths());
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function resolveGitSpec(MissionContext $context): array
    {
        $gitAttr = $context->attribute('git');
        $gitAttr = is_array($gitAttr) ? $gitAttr : [];

        $provider = $this->firstNonEmptyString([
            $gitAttr['provider'] ?? null,
            $context->attribute('provider'),
        ]);
        $repository = $this->firstNonEmptyString([
            $gitAttr['repository'] ?? null,
            $context->attribute('repository'),
        ]);

        if ($provider === null || $repository === null) {
            $mission = $this->tryLoadMission($context);
            if ($mission !== null) {
                $target = $mission->target();
                $provider = $provider ?? trim($target->provider());
                $repository = $repository ?? trim($target->repository());
            }
        }

        if ($provider === null || $repository === null || $provider === '' || $repository === '') {
            return [];
        }

        $git = [
            'provider' => $provider,
            'repository' => $repository,
        ];

        $baseBranch = $this->firstNonEmptyString([
            $gitAttr['baseBranch'] ?? null,
            $context->attribute('baseBranch'),
            $context->attribute('branch'),
        ]);
        if ($baseBranch !== null) {
            $git['baseBranch'] = $baseBranch;
        }

        return $git;
    }

    private function tryLoadMission(MissionContext $context): ?Mission
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

    /**
     * @param list<mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }
}
