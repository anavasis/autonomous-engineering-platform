<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;

/**
 * Declarative local executor stub.
 * No filesystem writes, shell, network, Git, Docker, SSH, HTTP, or AI.
 */
final class DeclarativeLocalExecutor implements Executor
{
    public const ID = 'declarative_local';

    public function id(): string
    {
        return self::ID;
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $action = $request->action();

        if ($action === 'unsupported') {
            return ExecutionResult::rejected(
                self::ID,
                'action is unsupported by DeclarativeLocalExecutor',
                $request->context()
            );
        }

        if ($action === 'simulate_fail') {
            return ExecutionResult::failed(
                self::ID,
                'simulated execution failure',
                $request->context()
            );
        }

        if ($request->contextValue('forceFail') === true) {
            return ExecutionResult::failed(
                self::ID,
                'execution failed by forceFail context flag',
                $request->context()
            );
        }

        $context = $request->context();
        $context['executedAction'] = $action;
        $context['missionId'] = $request->missionId();

        return ExecutionResult::succeeded(
            self::ID,
            'declarative local execution acknowledged',
            $context
        );
    }
}
