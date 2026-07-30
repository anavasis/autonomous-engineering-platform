<?php

declare(strict_types=1);

namespace Aep\Application\Execution;

/**
 * Thin execution runner. No Mission or Validation coupling.
 */
final class ExecutionService
{
    public function __construct(
        private Executor $executor
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        try {
            $result = $this->executor->execute($request);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Executor execution failed: ' . $this->executor->id() . ' — ' . $e->getMessage(),
                0,
                $e
            );
        }

        if ($result->executorId() !== $this->executor->id()) {
            throw new \RuntimeException(
                'Executor returned mismatched executorId. Expected '
                . $this->executor->id() . ', got ' . $result->executorId() . '.'
            );
        }

        return $result;
    }
}
