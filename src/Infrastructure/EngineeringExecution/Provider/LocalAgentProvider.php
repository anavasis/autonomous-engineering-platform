<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Deterministic local engineering agent used for tests and offline execution.
 */
final class LocalAgentProvider extends AbstractBufferedProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $id = is_string($options['id'] ?? null) ? (string) $options['id'] : 'local-agent';
        $name = is_string($options['displayName'] ?? null) ? (string) $options['displayName'] : 'Local Agent';
        parent::__construct(
            $id,
            $name,
            new ProviderCapabilities(
                streaming: true,
                cancel: true,
                resume: true,
                workspaceMount: true,
                diffExport: true,
                tools: true,
                maxContextTokens: 64000,
                supportsImages: false,
                costReporting: true,
                parallelSessions: true,
            )
        );
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth('ok', 'Local agent ready', Utc::now());
    }

    protected function run(ProviderSessionRequest $request): void
    {
        if ($this->isCancelled($request->sessionId())) {
            return;
        }
        $this->push($request->sessionId(), 'log', 'Applying local agent patch');

        $forceFail = ($request->providerOptions()['forceFail'] ?? false) === true
            || $request->action() === 'simulate_fail';
        if ($forceFail) {
            $this->complete(
                $request->sessionId(),
                new ProviderResult(ProviderResult::FAILED, 'local agent simulated failure', [], null, $this->estimateUsage($request))
            );

            return;
        }

        $workspace = rtrim($request->workspacePath(), '/');
        $targetRel = 'src/local_agent_change.txt';
        $contextFile = $workspace . '/context/' . $targetRel;
        $parent = dirname($contextFile);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        $payload = "local-agent:" . $request->action() . ":" . Utc::now() . "\n";
        file_put_contents($contextFile, $payload);
        $diff = "--- a/{$targetRel}\n+++ b/{$targetRel}\n@@\n+{$payload}";
        file_put_contents($workspace . '/RESULT.diff', $diff);

        $usage = $this->estimateUsage($request, 1);
        $this->push($request->sessionId(), 'diff', 'Wrote ' . $targetRel, ['path' => $targetRel]);
        $this->complete(
            $request->sessionId(),
            new ProviderResult(
                ProviderResult::SUCCEEDED,
                'local agent completed ' . $request->action(),
                [$targetRel],
                $diff,
                $usage,
                ['provider' => $this->id()]
            )
        );
    }
}
