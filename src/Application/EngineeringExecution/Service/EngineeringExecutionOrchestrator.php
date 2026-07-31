<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\ProviderEvent;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\EngineeringExecution\Port\EngineeringExecutionProvider;
use Aep\Application\EngineeringExecution\Port\ExecutionSessionStore;
use Aep\Application\EngineeringExecution\Port\ProviderRegistry;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Provider-agnostic engineering execution orchestrator.
 *
 * Mission Engine never calls this directly — ProviderRoutingExecutor does.
 * Adding a new provider must not require changes here.
 */
final class EngineeringExecutionOrchestrator
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ExecutionSessionStore $sessions,
        private readonly PromptPipeline $prompts,
        private readonly ContextPackager $context,
        private readonly WorkspacePreparer $workspace,
        private readonly DiffCollector $diffs,
        private readonly ArtifactCapture $artifacts,
        private readonly ResultNormalizer $normalizer,
        private readonly string $routerId = 'provider_routing',
        private readonly int $maxRetries = 2,
        private readonly int $pollIntervalMs = 20,
        private readonly int $maxPolls = 50,
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $providerId = trim((string) $request->contextValue('providerId', ''));
        if ($providerId === '') {
            return ExecutionResult::rejected($this->routerId, 'providerId is required for engineering execution orchestration');
        }

        $fallback = $this->fallbackChain($request, $providerId);
        $sessionId = 'esess_' . bin2hex(random_bytes(8));
        $now = Utc::now();
        $runId = $request->contextValue('runId');
        $runId = is_string($runId) && $runId !== '' ? $runId : 'run_exec';
        $allowedPaths = $this->stringList($request->contextValue('allowedPaths'), ['src/']);
        $nonGoals = $this->stringList($request->contextValue('nonGoals'), []);

        $session = new ExecutionSession(
            $sessionId,
            $request->missionId(),
            $runId,
            $providerId,
            ExecutionSession::STATUS_PLANNED,
            $now,
            $now,
            'Session created',
            [],
            $fallback,
        );
        $this->sessions->save($session);
        $this->audit($sessionId, 'session.created', ['providerId' => $providerId, 'fallback' => $fallback]);

        $started = microtime(true);
        $lastResult = null;
        $prompt = null;

        try {
            if (!$this->registry->has($providerId)) {
                throw new \InvalidArgumentException('Unknown execution provider: ' . $providerId);
            }

            foreach ($fallback as $hop => $candidateId) {
                if (!$this->registry->has($candidateId)) {
                    continue;
                }
                $session = $this->requireSession($sessionId);
                if ($hop > 0) {
                    $session->usage()->incrementFallbackHops();
                    $session->setProviderId($candidateId);
                    $session->setStatus(ExecutionSession::STATUS_RUNNING, Utc::now(), 'Fallback to ' . $candidateId);
                    $this->sessions->save($session);
                    $this->audit($sessionId, 'provider.fallback', ['providerId' => $candidateId, 'hop' => $hop]);
                }

                $provider = $this->registry->get($candidateId);
                $health = $provider->health();
                $this->audit($sessionId, 'provider.health', $health->toArray() + [
                    'providerId' => $candidateId,
                    'capabilities' => $provider->capabilities()->toArray(),
                ]);
                if (!$health->isAvailable()) {
                    $lastResult = new ProviderResult(
                        ProviderResult::REJECTED,
                        'Provider unavailable: ' . $health->message()
                    );
                    continue;
                }

                $session = $this->requireSession($sessionId);
                $session->setStatus(ExecutionSession::STATUS_PREPARING, Utc::now(), 'Preparing workspace');
                $this->sessions->save($session);

                $pack = $this->context->pack($request, $allowedPaths);
                $prompt = $this->prompts->build($request, $allowedPaths, $nonGoals, $pack['notes']);
                $session->setPrompt($prompt);
                $projectId = $request->contextValue('projectId');
                $artifactMounts = $request->contextValue('artifactMounts');
                $gitSpec = $request->contextValue('git');
                $workspacePath = $this->workspace->prepare($sessionId, $prompt, $pack['files'], [
                    'missionId' => $request->missionId(),
                    'runId' => $runId,
                    'projectId' => is_string($projectId) ? $projectId : null,
                    'allowedPaths' => $allowedPaths,
                    'artifactMounts' => is_array($artifactMounts) ? $artifactMounts : [],
                    'git' => is_array($gitSpec) ? $gitSpec : [],
                    'redactions' => is_int($pack['redactions'] ?? null) ? $pack['redactions'] : 0,
                ]);
                $session->setCheckpoint([
                    'id' => 'cp_' . bin2hex(random_bytes(4)),
                    'phase' => 'workspace_prepared',
                    'workspacePath' => $workspacePath,
                    'at' => Utc::now(),
                ]);
                $session->setStatus(ExecutionSession::STATUS_PROMPTING, Utc::now(), 'Prompt ready');
                $this->sessions->save($session);

                $timeout = $request->contextValue('timeoutSeconds');
                $timeoutSeconds = is_int($timeout) ? max(1, $timeout) : 300;
                $options = $request->contextValue('providerOptions');
                $options = is_array($options) ? $options : [];

                $providerRequest = new ProviderSessionRequest(
                    $sessionId,
                    $request->missionId(),
                    $runId,
                    $request->action(),
                    $prompt,
                    $workspacePath,
                    $allowedPaths,
                    $nonGoals,
                    $timeoutSeconds,
                    $options,
                    $session->checkpoint(),
                );

                $session->setStatus(ExecutionSession::STATUS_RUNNING, Utc::now(), 'Provider started');
                $this->sessions->save($session);

                $lastResult = $this->driveProvider($provider, $providerRequest, $sessionId);
                $session = $this->requireSession($sessionId);

                if ($session->cancelRequested() || $lastResult->status() === ProviderResult::CANCELLED) {
                    $provider->cancel($sessionId, 'Cancelled by operator.');
                    $session->setStatus(ExecutionSession::STATUS_CANCELLED, Utc::now(), 'Cancelled');
                    $this->sessions->save($session);
                    $this->audit($sessionId, 'session.cancelled', []);

                    return $this->normalizer->toExecutionResult(
                        $this->routerId,
                        $session,
                        new ProviderResult(ProviderResult::CANCELLED, 'Cancelled.'),
                        ['cancelled' => true]
                    );
                }

                if ($lastResult->isSucceeded()) {
                    break;
                }

                if ($lastResult->isRejected()) {
                    break;
                }

                $session->usage()->incrementRetries();
                $this->sessions->save($session);
            }

            if ($lastResult === null) {
                throw new \RuntimeException('No execution provider available.');
            }

            $session = $this->requireSession($sessionId);
            $session->setStatus(ExecutionSession::STATUS_COLLECTING, Utc::now(), 'Collecting diffs');
            $this->sessions->save($session);

            $checkpoint = $session->checkpoint() ?? [];
            $workspacePath = is_string($checkpoint['workspacePath'] ?? null)
                ? (string) $checkpoint['workspacePath']
                : '';
            $diff = $this->diffs->collect($workspacePath, $allowedPaths);
            $files = $diff['files'] !== [] ? $diff['files'] : $lastResult->filesChanged();
            $session->usage()->setFilesChanged(count($files));
            $session->usage()->addTokens(
                $lastResult->usage()->toArray()['inputTokens'],
                $lastResult->usage()->toArray()['outputTokens']
            );
            $session->usage()->addCost((float) $lastResult->usage()->toArray()['costUsd']);
            $session->usage()->setDuration(microtime(true) - $started);

            $session->setStatus(ExecutionSession::STATUS_CAPTURING, Utc::now(), 'Capturing artifacts');
            $this->sessions->save($session);

            $logText = $this->renderLog($sessionId);
            $promptBundle = $session->prompt() ?? $prompt;
            if ($promptBundle === null) {
                $promptBundle = $this->prompts->build($request, $allowedPaths, $nonGoals);
            }
            $artifactMap = $this->artifacts->capture(
                $session,
                $promptBundle,
                $diff['text'] !== '' ? $diff['text'] : (string) ($lastResult->diffText() ?? ''),
                $logText,
                $session->usage()->toArray(),
                $session->checkpoint(),
            );
            $session->setArtifacts($artifactMap);
            $session->setCheckpoint([
                'id' => 'cp_' . bin2hex(random_bytes(4)),
                'phase' => 'finished',
                'workspacePath' => $workspacePath,
                'at' => Utc::now(),
                'status' => $lastResult->status(),
            ]);

            $finalStatus = match ($lastResult->status()) {
                ProviderResult::SUCCEEDED => ExecutionSession::STATUS_SUCCEEDED,
                ProviderResult::REJECTED => ExecutionSession::STATUS_REJECTED,
                ProviderResult::CANCELLED => ExecutionSession::STATUS_CANCELLED,
                ProviderResult::TIMED_OUT => ExecutionSession::STATUS_TIMED_OUT,
                default => ExecutionSession::STATUS_FAILED,
            };
            $session->setStatus($finalStatus, Utc::now(), $lastResult->message());
            $this->sessions->save($session);
            $this->audit($sessionId, 'session.finished', [
                'status' => $finalStatus,
                'usage' => $session->usage()->toArray(),
                'artifacts' => $artifactMap,
            ]);

            return $this->normalizer->toExecutionResult($this->routerId, $session, $lastResult, [
                'filesChanged' => $files,
            ]);
        } catch (\Throwable $e) {
            $session = $this->sessions->find($sessionId);
            if ($session !== null) {
                $session->setStatus(ExecutionSession::STATUS_FAILED, Utc::now(), $e->getMessage());
                $session->usage()->setDuration(microtime(true) - $started);
                $this->sessions->save($session);
            }
            $this->audit($sessionId, 'session.failed', ['error' => $e->getMessage()]);

            return ExecutionResult::failed($this->routerId, $e->getMessage(), [
                'sessionId' => $sessionId,
                'providerId' => $providerId,
            ]);
        }
    }

    public function cancel(string $sessionId, string $reason = 'Cancelled by operator.'): void
    {
        $session = $this->requireSession($sessionId);
        $session->requestCancel();
        $session->setStatus($session->status(), Utc::now(), $reason);
        $this->sessions->save($session);
        if ($this->registry->has($session->providerId())) {
            $this->registry->get($session->providerId())->cancel($sessionId, $reason);
        }
        $this->audit($sessionId, 'cancel.requested', ['reason' => $reason]);
    }

    public function resume(string $sessionId): ExecutionResult
    {
        $session = $this->requireSession($sessionId);
        if (!in_array($session->status(), [
            ExecutionSession::STATUS_FAILED,
            ExecutionSession::STATUS_CANCELLED,
            ExecutionSession::STATUS_TIMED_OUT,
        ], true)) {
            throw new \RuntimeException('Session cannot be resumed from status ' . $session->status());
        }
        if (!$this->registry->has($session->providerId())) {
            throw new \RuntimeException('Provider unavailable: ' . $session->providerId());
        }

        $provider = $this->registry->get($session->providerId());
        $checkpoint = $session->checkpoint() ?? [];
        $session->setStatus(ExecutionSession::STATUS_RUNNING, Utc::now(), 'Resuming');
        $this->sessions->save($session);
        $this->audit($sessionId, 'session.resume', ['checkpoint' => $checkpoint]);

        $provider->resume($sessionId, $checkpoint);
        $result = $this->drain($provider, $sessionId);
        $session = $this->requireSession($sessionId);
        $session->usage()->addTokens(
            $result->usage()->toArray()['inputTokens'],
            $result->usage()->toArray()['outputTokens']
        );
        $finalStatus = $result->isSucceeded()
            ? ExecutionSession::STATUS_SUCCEEDED
            : ExecutionSession::STATUS_FAILED;
        $session->setStatus($finalStatus, Utc::now(), $result->message());
        $this->sessions->save($session);

        return $this->normalizer->toExecutionResult($this->routerId, $session, $result);
    }

    private function driveProvider(
        EngineeringExecutionProvider $provider,
        ProviderSessionRequest $request,
        string $sessionId,
    ): ProviderResult {
        $attempt = 0;
        $last = null;
        while ($attempt <= $this->maxRetries) {
            $session = $this->requireSession($sessionId);
            if ($session->cancelRequested()) {
                $provider->cancel($sessionId);

                return new ProviderResult(ProviderResult::CANCELLED, 'Cancelled.');
            }

            try {
                $this->appendOrchestratorEvent($sessionId, 'provider.start', 'Starting provider attempt ' . ($attempt + 1));
                $provider->start($request);
                $last = $this->drain($provider, $sessionId);
                if ($last->isSucceeded() || $last->status() === ProviderResult::CANCELLED || $last->isRejected()) {
                    return $last;
                }
            } catch (\Throwable $e) {
                $last = new ProviderResult(ProviderResult::FAILED, $e->getMessage());
                $this->appendOrchestratorEvent($sessionId, 'provider.error', $e->getMessage(), [
                    'attempt' => $attempt + 1,
                ]);
            }

            $attempt++;
            if ($attempt <= $this->maxRetries) {
                $session = $this->requireSession($sessionId);
                $session->usage()->incrementRetries();
                $session->setCheckpoint([
                    'id' => 'cp_retry_' . $attempt,
                    'phase' => 'retry',
                    'attempt' => $attempt,
                    'at' => Utc::now(),
                    'workspacePath' => $request->workspacePath(),
                ]);
                $this->sessions->save($session);
                $this->appendOrchestratorEvent($sessionId, 'provider.retry', 'Retrying provider', [
                    'attempt' => $attempt,
                ]);
            }
        }

        return $last ?? new ProviderResult(ProviderResult::FAILED, 'Provider failed.');
    }

    private function drain(EngineeringExecutionProvider $provider, string $sessionId): ProviderResult
    {
        $afterSeq = 0;
        for ($i = 0; $i < $this->maxPolls; $i++) {
            $session = $this->requireSession($sessionId);
            if ($session->cancelRequested()) {
                $provider->cancel($sessionId);

                return new ProviderResult(ProviderResult::CANCELLED, 'Cancelled.');
            }

            $events = $provider->poll($sessionId, $afterSeq);
            $sawTerminal = false;
            foreach ($events as $event) {
                $this->sessions->appendEvent($sessionId, $event);
                $afterSeq = max($afterSeq, $event->seq());
                if (in_array($event->type(), [
                    'completed',
                    'failed',
                    'cancelled',
                    'rejected',
                    'timed_out',
                    'provider.completed',
                    'provider.failed',
                    'provider.cancelled',
                    'provider.rejected',
                    'provider.timed_out',
                ], true)) {
                    $sawTerminal = true;
                }
            }

            if ($sawTerminal || $i === 0) {
                try {
                    return $provider->collectResult($sessionId);
                } catch (\RuntimeException) {
                    // Provider not finished yet.
                }
            }

            usleep(max(1, $this->pollIntervalMs) * 1000);
        }

        return new ProviderResult(ProviderResult::TIMED_OUT, 'Provider poll timeout.');
    }

    private function appendOrchestratorEvent(string $sessionId, string $type, string $message, array $data = []): void
    {
        $events = $this->sessions->events($sessionId);
        $seq = $events === [] ? 1 : ($events[array_key_last($events)]->seq() + 1);
        $this->sessions->appendEvent($sessionId, new ProviderEvent($seq, $type, $message, Utc::now(), $data));
    }

    /** @param array<string, mixed> $data */
    private function audit(string $sessionId, string $type, array $data): void
    {
        $this->sessions->appendAudit([
            'at' => Utc::now(),
            'sessionId' => $sessionId,
            'type' => $type,
            'data' => $data,
        ]);
    }

    private function requireSession(string $sessionId): ExecutionSession
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            throw new \RuntimeException('Execution session disappeared: ' . $sessionId);
        }

        return $session;
    }

    /**
     * @return list<string>
     */
    private function fallbackChain(ExecutionRequest $request, string $primary): array
    {
        $chain = [$primary];
        $raw = $request->contextValue('fallbackProviders');
        if (is_array($raw)) {
            foreach ($raw as $id) {
                if (is_string($id) && $id !== '' && !in_array($id, $chain, true)) {
                    $chain[] = $id;
                }
            }
        }

        return $chain;
    }

    /**
     * @param mixed $value
     * @param list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out !== [] ? $out : $default;
    }

    private function renderLog(string $sessionId): string
    {
        $lines = [];
        foreach ($this->sessions->events($sessionId) as $event) {
            $lines[] = '[' . $event->atUtc() . '] #' . $event->seq() . ' ' . $event->type() . ' ' . $event->message();
        }

        return implode("\n", $lines);
    }
}
