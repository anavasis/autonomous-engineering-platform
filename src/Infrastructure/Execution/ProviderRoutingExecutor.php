<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution;

use Aep\Application\EngineeringExecution\Port\ExecutionSettingsStore;
use Aep\Application\EngineeringExecution\Service\EngineeringExecutionOrchestrator;
use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;

/**
 * Additive Executor adapter.
 *
 * - implement without providerId / defaultProviderId → rejected (fail closed).
 * - providerId selected → EngineeringExecutionOrchestrator (provider-agnostic).
 * - non-implement legacy actions may still use DeclarativeLocalExecutor.
 */
final class ProviderRoutingExecutor implements Executor
{
    public const ID = 'provider_routing';

    public function __construct(
        private readonly Executor $legacy,
        private readonly EngineeringExecutionOrchestrator $orchestrator,
        private readonly ?ExecutionSettingsStore $settings = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $providerId = $request->contextValue('providerId');
        if (!is_string($providerId) || trim($providerId) === '') {
            $default = $this->settings?->get()['defaultProviderId'] ?? null;
            if (!is_string($default) || trim($default) === '') {
                if ($this->isImplementAction($request)) {
                    return ExecutionResult::rejected(
                        self::ID,
                        'No real execution provider is configured. Set providerId or settings.defaultProviderId before implement.'
                    );
                }

                return $this->wrapLegacy($this->legacy->execute($request));
            }
            $providerId = trim($default);
        } else {
            $providerId = trim($providerId);
        }

        $context = $request->context();
        $context['providerId'] = $providerId;
        if (!isset($context['fallbackProviders']) && $this->settings !== null) {
            $fallback = $this->settings->get()['fallbackProviders'] ?? null;
            if (is_array($fallback)) {
                $context['fallbackProviders'] = $fallback;
            }
        }

        $routed = new ExecutionRequest(
            $request->missionId(),
            $request->action(),
            $request->occurredAtUtc(),
            $context
        );

        $result = $this->orchestrator->execute($routed);
        $ctx = $result->context();
        $ctx['actualExecutorId'] = $result->executorId();
        $ctx['routedProviderId'] = $providerId;

        if ($result->isSucceeded()) {
            return ExecutionResult::succeeded(self::ID, $result->message(), $ctx);
        }
        if ($result->isRejected()) {
            return ExecutionResult::rejected(self::ID, $result->message(), $ctx);
        }

        return ExecutionResult::failed(self::ID, $result->message(), $ctx);
    }

    private function isImplementAction(ExecutionRequest $request): bool
    {
        return trim($request->action()) === 'implement';
    }

    private function wrapLegacy(ExecutionResult $result): ExecutionResult
    {
        $context = $result->context();
        $context['actualExecutorId'] = $result->executorId();
        $context['legacyBypass'] = true;

        if ($result->isSucceeded()) {
            return ExecutionResult::succeeded(self::ID, $result->message(), $context);
        }
        if ($result->isRejected()) {
            return ExecutionResult::rejected(self::ID, $result->message(), $context);
        }

        return ExecutionResult::failed(self::ID, $result->message(), $context);
    }
}
