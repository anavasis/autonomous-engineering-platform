<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Bridge;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\Executor;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Infrastructure\EngineeringExecution\Provider\AbstractBufferedProvider;

/**
 * Exposes an existing Executor (Local/SSH) through the EngineeringExecutionProvider contract.
 * Does not alter legacy executor semantics.
 */
final class LegacyExecutorBridgeProvider extends AbstractBufferedProvider
{
    private readonly Executor $executor;

    /** @param array<string, mixed> $options */
    public function __construct(Executor $executor, array $options = [])
    {
        $this->executor = $executor;
        $id = is_string($options['id'] ?? null) ? (string) $options['id'] : 'legacy-' . $executor->id();
        $name = is_string($options['displayName'] ?? null)
            ? (string) $options['displayName']
            : 'Legacy ' . $executor->id();
        parent::__construct(
            $id,
            $name,
            new ProviderCapabilities(
                streaming: false,
                cancel: false,
                resume: false,
                workspaceMount: false,
                diffExport: false,
                tools: false,
                maxContextTokens: 0,
                costReporting: false,
            )
        );
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth('ok', 'Legacy executor bridge ready', Utc::now());
    }

    protected function run(ProviderSessionRequest $request): void
    {
        $execRequest = new ExecutionRequest(
            $request->missionId(),
            $request->action(),
            Utc::now(),
            array_merge($request->providerOptions(), [
                'runId' => $request->runId(),
                'bridgedSessionId' => $request->sessionId(),
            ])
        );
        $result = $this->executor->execute($execRequest);
        $status = $result->isSucceeded()
            ? ProviderResult::SUCCEEDED
            : ($result->isRejected() ? ProviderResult::REJECTED : ProviderResult::FAILED);
        $this->complete(
            $request->sessionId(),
            new ProviderResult(
                $status,
                $result->message(),
                [],
                null,
                $this->estimateUsage($request),
                ['legacyExecutorId' => $this->executor->id(), 'legacyContext' => $result->context()]
            )
        );
    }
}
