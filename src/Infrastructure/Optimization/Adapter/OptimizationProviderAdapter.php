<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Optimization\Adapter;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;
use Aep\Application\Optimization\Port\OptimizationSettingsStore;
use Aep\Application\Optimization\Service\OptimizationEngine;

/**
 * Decorates Executor: scores/selects providerId before ProviderRoutingExecutor.
 * Does not modify EngineeringExecutionProvider contracts.
 */
final class OptimizationProviderAdapter implements Executor
{
    public const ID = 'optimization_provider';

    public function __construct(
        private readonly Executor $inner,
        private readonly OptimizationEngine $engine,
        private readonly OptimizationSettingsStore $settings,
    ) {}

    public function id(): string { return self::ID; }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $settings = $this->settings->get();
        $context = $request->context();
        if (($settings['enabled'] ?? true) === true && ($settings['assistExecution'] ?? true) === true) {
            $existing = $request->contextValue('providerId');
            if (!is_string($existing) || trim($existing) === '') {
                try {
                    $decision = $this->engine->decide([
                        'missionId' => $request->missionId(),
                        'preferredProviderId' => is_string($context['preferredProviderId'] ?? null) ? $context['preferredProviderId'] : null,
                        'priority' => is_string($context['priority'] ?? null) ? $context['priority'] : 'normal',
                        'tokens' => is_numeric($context['tokens'] ?? null) ? (float) $context['tokens'] : 2000.0,
                        'minutes' => 1.0,
                    ], false);
                    if (is_string($decision->selectedProviderId()) && $decision->selectedProviderId() !== '') {
                        $context['providerId'] = $decision->selectedProviderId();
                        $context['fallbackProviders'] = $decision->fallbackProviders();
                        $context['optimizationDecisionId'] = $decision->decisionId();
                        $context['optimizationEstimatedCost'] = $decision->estimatedCost();
                    }
                } catch (\Throwable) {
                }
            }
        }

        $routed = new ExecutionRequest(
            $request->missionId(),
            $request->action(),
            $request->occurredAtUtc(),
            $context,
        );
        $result = $this->inner->execute($routed);

        try {
            $providerId = $result->context()['routedProviderId'] ?? $context['providerId'] ?? null;
            if (is_string($providerId) && $providerId !== '') {
                $actual = 0.0;
                $usage = $result->context()['usage'] ?? $result->context()['metrics'] ?? null;
                if (is_array($usage) && is_numeric($usage['costUsd'] ?? null)) {
                    $actual = (float) $usage['costUsd'];
                } elseif (is_numeric($context['optimizationEstimatedCost'] ?? null)) {
                    $actual = (float) $context['optimizationEstimatedCost'];
                }
                if ($actual > 0) {
                    $this->engine->recordActualCost(
                        $providerId,
                        $actual,
                        $request->missionId(),
                        is_numeric($context['optimizationEstimatedCost'] ?? null) ? (float) $context['optimizationEstimatedCost'] : $actual,
                    );
                }
            }
        } catch (\Throwable) {
        }

        return $result;
    }
}
