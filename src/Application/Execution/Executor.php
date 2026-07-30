<?php

declare(strict_types=1);

namespace Aep\Application\Execution;

/**
 * Application port for executing approved actions.
 */
interface Executor
{
    public function id(): string;

    public function execute(ExecutionRequest $request): ExecutionResult;
}
