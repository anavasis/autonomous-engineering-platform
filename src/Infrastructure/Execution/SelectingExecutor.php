<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;

/**
 * Routes ExecutionRequest to local or SSH Executor without changing ExecutionService.
 *
 * Result executorId is always {@see self::ID}; actual executor id is in context.actualExecutorId.
 */
final class SelectingExecutor implements Executor
{
    public const ID = 'selecting';

    /**
     * @param array<string, Executor> $executors keyed by target name (local|ssh)
     */
    public function __construct(
        private readonly array $executors,
        private readonly string $defaultTarget = 'local',
    ) {
        if ($this->executors === []) {
            throw new \InvalidArgumentException('SelectingExecutor requires at least one Executor.');
        }
        foreach ($this->executors as $name => $executor) {
            if (!is_string($name) || $name === '' || !$executor instanceof Executor) {
                throw new \InvalidArgumentException('SelectingExecutor executors must be named Executor instances.');
            }
        }
        if (!isset($this->executors[$this->defaultTarget])) {
            throw new \InvalidArgumentException('defaultTarget is not registered: ' . $this->defaultTarget);
        }
    }

    public function id(): string
    {
        return self::ID;
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $target = $this->selectTarget($request);
        if (!isset($this->executors[$target])) {
            return ExecutionResult::rejected(self::ID, 'No executor registered for target: ' . $target, [
                'selectedTarget' => $target,
            ]);
        }

        $inner = $this->executors[$target];
        $result = $inner->execute($request);
        $context = $result->context();
        $context['actualExecutorId'] = $result->executorId();
        $context['selectedTarget'] = $target;

        if ($result->isSucceeded()) {
            return ExecutionResult::succeeded(self::ID, $result->message(), $context);
        }
        if ($result->isRejected()) {
            return ExecutionResult::rejected(self::ID, $result->message(), $context);
        }

        return ExecutionResult::failed(self::ID, $result->message(), $context);
    }

    private function selectTarget(ExecutionRequest $request): string
    {
        $executor = $request->contextValue('executor');
        if (is_string($executor) && trim($executor) !== '') {
            return strtolower(trim($executor));
        }

        $access = $request->contextValue('accessMethod');
        if (is_string($access) && strtolower(trim($access)) === 'ssh') {
            return 'ssh';
        }
        if (is_string($access) && strtolower(trim($access)) === 'local') {
            return 'local';
        }

        // Convenience: presence of SSH host defaults toward ssh when registered.
        if ($request->contextValue('host') !== null && isset($this->executors['ssh'])) {
            return 'ssh';
        }

        return $this->defaultTarget;
    }
}
