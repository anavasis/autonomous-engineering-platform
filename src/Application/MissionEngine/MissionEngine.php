<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine;

use Aep\Application\Execution\ExecutionService;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\Validation\ValidationPipeline;
use Aep\Domain\Mission\MissionRepository;
use Aep\Domain\Mission\ValueObject\MissionId;

/**
 * ORCH-R9 Mission Engine — Application orchestrator only.
 *
 * Mutates Domain Mission exclusively through MissionCommandService.
 */
final class MissionEngine
{
    /** @var array<string, CancellationToken> */
    private array $tokens = [];

    public function __construct(
        private readonly MissionCommandService $missions,
        private readonly MissionRepository $missionRepository,
        private readonly MissionRunRepository $runs,
        private readonly MissionPlanFactory $planFactory,
        private readonly ExecutionService $execution,
        private readonly ValidationPipeline $validation,
    ) {
    }

    public function start(MissionEngineRequest $request): MissionRunResult
    {
        if ($this->runs->exists($request->runId())) {
            throw new \InvalidArgumentException('Run already exists: ' . $request->runId());
        }

        $token = new CancellationToken();
        $this->tokens[$request->runId()] = $token;

        $context = $this->newContext($request, $token, $request->attributes());
        $plan = $this->planFactory->build($context);
        $mergedAttributes = array_merge($request->attributes(), $this->planFactory->runAttributes());
        $context = $this->newContext($request, $token, $mergedAttributes);

        $checkpoint = new MissionCheckpoint(
            $request->runId(),
            $request->missionId(),
            MissionRunState::RUNNING,
            $plan->stepIds(),
            0,
            [],
            0,
            $request->occurredAtUtc(),
            $request->projectId(),
            $mergedAttributes,
            'Run started.'
        );
        $timeline = new MissionTimeline();
        $timeline->append(new MissionTimelineEntry(
            $request->occurredAtUtc(),
            '',
            'run_started',
            MissionRunState::RUNNING,
            0.0,
            'Run started.'
        ));
        $this->runs->save($checkpoint, $timeline);

        return $this->drive(
            $context,
            $plan,
            $checkpoint,
            $timeline,
            $request->retryPolicy(),
            $request->timeoutPolicy(),
            $request->occurredAtUtc()
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function resume(string $runId, array $attributes = [], ?string $occurredAtUtc = null): MissionRunResult
    {
        $checkpoint = $this->runs->getCheckpoint($runId);
        $timeline = $this->runs->getTimeline($runId);
        $state = new MissionRunState($checkpoint->engineState());

        if ($state->isTerminal()) {
            return $this->toResult($checkpoint, $state->toString() . ' run cannot be resumed.');
        }
        if (!$state->is(MissionRunState::WAITING) && !$state->is(MissionRunState::SUSPENDED)) {
            return $this->toResult($checkpoint, 'Run is not resumable from state ' . $state->toString() . '.');
        }

        $at = $occurredAtUtc ?? $checkpoint->updatedAtUtc();
        // Resume clears cooperative cancellation so a prior cancel can be continued.
        $token = new CancellationToken();
        $this->tokens[$runId] = $token;

        $merged = array_merge($checkpoint->attributes(), $attributes);
        $actorType = is_string($merged['actorType'] ?? null) ? $merged['actorType'] : 'system';
        $actorId = is_string($merged['actorId'] ?? null) ? $merged['actorId'] : 'mission-engine';

        $retry = new RetryPolicy(
            is_int($merged['retryMaxAttempts'] ?? null) ? $merged['retryMaxAttempts'] : 3,
            is_int($merged['retryBackoffMs'] ?? null) ? $merged['retryBackoffMs'] : 0,
        );
        $timeoutSeconds = 0.0;
        if (is_float($merged['stepTimeoutSeconds'] ?? null) || is_int($merged['stepTimeoutSeconds'] ?? null)) {
            $timeoutSeconds = (float) $merged['stepTimeoutSeconds'];
        }
        $timeout = new TimeoutPolicy($timeoutSeconds);

        $request = new MissionEngineRequest(
            $checkpoint->runId(),
            $checkpoint->missionId(),
            $at,
            $actorType,
            $actorId,
            $merged,
            $checkpoint->projectId(),
            $retry,
            $timeout,
        );

        $context = $this->newContext($request, $token, $merged);
        $plan = $this->planFactory->build($context);

        $checkpoint = new MissionCheckpoint(
            $checkpoint->runId(),
            $checkpoint->missionId(),
            MissionRunState::RUNNING,
            $plan->stepIds(),
            $checkpoint->cursorIndex(),
            $checkpoint->attemptByStepId(),
            $checkpoint->progressPercent(),
            $at,
            $checkpoint->projectId(),
            $merged,
            'Run resumed.'
        );
        $timeline->append(new MissionTimelineEntry(
            $at,
            '',
            'run_resumed',
            MissionRunState::RUNNING,
            0.0,
            'Run resumed.'
        ));
        $this->runs->save($checkpoint, $timeline);

        return $this->drive($context, $plan, $checkpoint, $timeline, $retry, $timeout, $at);
    }

    public function cancel(string $runId, string $reason = 'Cancelled by caller.', ?string $occurredAtUtc = null): MissionRunResult
    {
        $checkpoint = $this->runs->getCheckpoint($runId);
        $timeline = $this->runs->getTimeline($runId);
        $state = new MissionRunState($checkpoint->engineState());

        if ($state->isTerminal()) {
            return $this->toResult($checkpoint, 'Terminal run cannot be cancelled.');
        }

        $token = $this->tokens[$runId] ?? new CancellationToken();
        $token->cancel($reason);
        $this->tokens[$runId] = $token;

        $at = $occurredAtUtc ?? $checkpoint->updatedAtUtc();
        $checkpoint = new MissionCheckpoint(
            $checkpoint->runId(),
            $checkpoint->missionId(),
            MissionRunState::SUSPENDED,
            $checkpoint->planStepIds(),
            $checkpoint->cursorIndex(),
            $checkpoint->attemptByStepId(),
            $checkpoint->progressPercent(),
            $at,
            $checkpoint->projectId(),
            $checkpoint->attributes(),
            $reason
        );
        $timeline->append(new MissionTimelineEntry(
            $at,
            $checkpoint->planStepIds()[$checkpoint->cursorIndex()] ?? '',
            'run_cancelled',
            MissionRunState::SUSPENDED,
            0.0,
            $reason
        ));
        $this->runs->save($checkpoint, $timeline);

        return $this->toResult($checkpoint, $reason);
    }

    public function status(string $runId): MissionRunResult
    {
        $checkpoint = $this->runs->getCheckpoint($runId);

        return $this->toResult($checkpoint, $checkpoint->message());
    }

    private function drive(
        MissionContext $context,
        MissionPlan $plan,
        MissionCheckpoint $checkpoint,
        MissionTimeline $timeline,
        RetryPolicy $retryPolicy,
        TimeoutPolicy $timeoutPolicy,
        string $occurredAtUtc,
    ): MissionRunResult {
        $cursor = $checkpoint->cursorIndex();
        $attempts = $checkpoint->attemptByStepId();
        $attributes = $checkpoint->attributes();

        while ($cursor < $plan->size()) {
            if ($context->cancellation()->isCancelled()) {
                return $this->persistState(
                    $checkpoint,
                    $timeline,
                    $plan,
                    $cursor,
                    $attempts,
                    $attributes,
                    MissionRunState::SUSPENDED,
                    $context->cancellation()->reason(),
                    $occurredAtUtc,
                    'run_cancelled',
                    ''
                );
            }

            $step = $plan->stepAt($cursor);
            $attempt = ($attempts[$step->id()] ?? 0) + 1;
            $attempts[$step->id()] = $attempt;

            $timeline->append(new MissionTimelineEntry(
                $occurredAtUtc,
                $step->id(),
                'step_started',
                MissionRunState::RUNNING,
                0.0,
                'Starting ' . $step->name()
            ));

            $started = microtime(true);
            $result = $step->execute($context);
            $elapsed = microtime(true) - $started;

            if ($timeoutPolicy->exceeds($elapsed)) {
                $result = StepResult::timedOut(
                    'Step exceeded timeout of ' . $timeoutPolicy->stepTimeoutSeconds() . 's.'
                );
            }

            $timeline->append(new MissionTimelineEntry(
                $occurredAtUtc,
                $step->id(),
                'step_finished',
                $result->status(),
                $elapsed,
                $result->message()
            ));

            if ($result->isSucceeded()) {
                $attributes = $this->mergeStepContext($attributes, $result->context());
                $context = $context->withMergedAttributes($attributes);
                $cursor++;
                $progress = $this->progress($cursor, $plan->size());
                $checkpoint = new MissionCheckpoint(
                    $checkpoint->runId(),
                    $checkpoint->missionId(),
                    MissionRunState::RUNNING,
                    $plan->stepIds(),
                    $cursor,
                    $attempts,
                    $progress,
                    $occurredAtUtc,
                    $checkpoint->projectId(),
                    $attributes,
                    $result->message()
                );
                $this->runs->save($checkpoint, $timeline);
                continue;
            }

            if ($result->isWaiting()) {
                $attributes = $this->mergeStepContext($attributes, $result->context());
                return $this->persistState(
                    $checkpoint,
                    $timeline,
                    $plan,
                    $cursor,
                    $attempts,
                    $attributes,
                    MissionRunState::WAITING,
                    $result->message(),
                    $occurredAtUtc,
                    'run_waiting',
                    $step->id()
                );
            }

            if ($result->isCancelled()) {
                return $this->persistState(
                    $checkpoint,
                    $timeline,
                    $plan,
                    $cursor,
                    $attempts,
                    $attributes,
                    MissionRunState::SUSPENDED,
                    $result->message(),
                    $occurredAtUtc,
                    'run_cancelled',
                    $step->id()
                );
            }

            if ($result->isTimedOut()) {
                return $this->persistState(
                    $checkpoint,
                    $timeline,
                    $plan,
                    $cursor,
                    $attempts,
                    $attributes,
                    MissionRunState::TIMED_OUT,
                    $result->message(),
                    $occurredAtUtc,
                    'run_timed_out',
                    $step->id()
                );
            }

            if ($result->isRejected()) {
                $attributes = $this->mergeStepContext($attributes, $result->context());
                return $this->persistState(
                    $checkpoint,
                    $timeline,
                    $plan,
                    $cursor,
                    $attempts,
                    $attributes,
                    MissionRunState::FAILED,
                    $result->message(),
                    $occurredAtUtc,
                    'run_failed',
                    $step->id()
                );
            }

            if ($retryPolicy->shouldRetry($result, $attempt)) {
                if ($retryPolicy->backoffMs() > 0) {
                    usleep($retryPolicy->backoffMs() * 1000);
                }
                $timeline->append(new MissionTimelineEntry(
                    $occurredAtUtc,
                    $step->id(),
                    'step_retry',
                    $result->status(),
                    0.0,
                    'Retry scheduled (attempt ' . $attempt . '/' . $retryPolicy->maxAttempts() . ')'
                ));
                $checkpoint = new MissionCheckpoint(
                    $checkpoint->runId(),
                    $checkpoint->missionId(),
                    MissionRunState::RUNNING,
                    $plan->stepIds(),
                    $cursor,
                    $attempts,
                    $this->progress($cursor, $plan->size()),
                    $occurredAtUtc,
                    $checkpoint->projectId(),
                    $attributes,
                    $result->message()
                );
                $this->runs->save($checkpoint, $timeline);
                continue;
            }

            $attributes = $this->mergeStepContext($attributes, $result->context());
            return $this->persistState(
                $checkpoint,
                $timeline,
                $plan,
                $cursor,
                $attempts,
                $attributes,
                MissionRunState::FAILED,
                $result->message(),
                $occurredAtUtc,
                'run_failed',
                $step->id()
            );
        }

        return $this->persistState(
            $checkpoint,
            $timeline,
            $plan,
            $cursor,
            $attempts,
            $attributes,
            MissionRunState::COMPLETED,
            'Mission run completed.',
            $occurredAtUtc,
            'run_completed',
            ''
        );
    }

    /**
     * @param array<string, int> $attempts
     * @param array<string, mixed> $attributes
     */
    private function persistState(
        MissionCheckpoint $checkpoint,
        MissionTimeline $timeline,
        MissionPlan $plan,
        int $cursor,
        array $attempts,
        array $attributes,
        string $engineState,
        string $message,
        string $occurredAtUtc,
        string $event,
        string $stepId,
    ): MissionRunResult {
        $progress = $engineState === MissionRunState::COMPLETED
            ? 100
            : $this->progress($cursor, $plan->size());

        $checkpoint = new MissionCheckpoint(
            $checkpoint->runId(),
            $checkpoint->missionId(),
            $engineState,
            $plan->stepIds(),
            $cursor,
            $attempts,
            $progress,
            $occurredAtUtc,
            $checkpoint->projectId(),
            $attributes,
            $message
        );
        $timeline->append(new MissionTimelineEntry(
            $occurredAtUtc,
            $stepId,
            $event,
            $engineState,
            0.0,
            $message
        ));
        $this->runs->save($checkpoint, $timeline);

        return $this->toResult($checkpoint, $message, $stepId !== '' ? $stepId : null);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function newContext(
        MissionEngineRequest $request,
        CancellationToken $token,
        array $attributes,
    ): MissionContext {
        $merged = $attributes;
        $merged['actorType'] = $request->actorType();
        $merged['actorId'] = $request->actorId();
        $merged['retryMaxAttempts'] = $request->retryPolicy()->maxAttempts();
        $merged['retryBackoffMs'] = $request->retryPolicy()->backoffMs();
        $merged['stepTimeoutSeconds'] = $request->timeoutPolicy()->stepTimeoutSeconds();

        return new MissionContext(
            $request->runId(),
            $request->missionId(),
            $request->occurredAtUtc(),
            $request->actorType(),
            $request->actorId(),
            $this->missions,
            $token,
            $merged,
            $request->projectId(),
            $this->execution,
            $this->validation,
            $this->readMissionState($request->missionId())
        );
    }

    private function readMissionState(string $missionId): string
    {
        try {
            return $this->missionRepository->get(new MissionId($missionId))->state()->toString();
        } catch (\Throwable) {
            return '';
        }
    }

    private function progress(int $completedSteps, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) floor(($completedSteps / $total) * 100);
    }

    /**
     * Merge StepResult context into durable checkpoint attributes.
     * Protected workflow/control keys are never overwritten by step context.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $stepContext
     * @return array<string, mixed>
     */
    private function mergeStepContext(array $attributes, array $stepContext): array
    {
        if ($stepContext === []) {
            return $attributes;
        }

        $protected = [
            'workflowId' => true,
            'workflowVersion' => true,
            'allowedPaths' => true,
            'nonGoals' => true,
            'executionAction' => true,
            'intakeId' => true,
            'conversationId' => true,
            'planId' => true,
            'confirmedBy' => true,
        ];

        foreach ($stepContext as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (isset($protected[$key])) {
                continue;
            }
            if (str_starts_with($key, 'gate.')) {
                continue;
            }
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    private function toResult(
        MissionCheckpoint $checkpoint,
        string $message,
        ?string $failedOrCurrentStepId = null
    ): MissionRunResult {
        $state = new MissionRunState($checkpoint->engineState());
        $current = null;
        $failed = null;
        if ($state->is(MissionRunState::FAILED) || $state->is(MissionRunState::TIMED_OUT)) {
            $failed = $failedOrCurrentStepId ?? ($checkpoint->planStepIds()[$checkpoint->cursorIndex()] ?? null);
        } elseif (!$state->is(MissionRunState::COMPLETED)) {
            $current = $failedOrCurrentStepId ?? ($checkpoint->planStepIds()[$checkpoint->cursorIndex()] ?? null);
        }

        return new MissionRunResult(
            $checkpoint->runId(),
            $checkpoint->missionId(),
            $state,
            $this->readMissionState($checkpoint->missionId()),
            $checkpoint->progressPercent(),
            $checkpoint->completedStepIds(),
            $message,
            $failed,
            $current
        );
    }
}
