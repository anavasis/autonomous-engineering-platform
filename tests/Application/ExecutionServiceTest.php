<?php

declare(strict_types=1);

namespace Tests\Application;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\ExecutionService;
use Aep\Application\Execution\Executor;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Tests\Support\Assert;

/**
 * ORCH-R6 executor abstraction coverage.
 */
final class ExecutionServiceTest
{
    public function test_succeeded(): void
    {
        $service = new ExecutionService(new DeclarativeLocalExecutor());
        $result = $service->execute(new ExecutionRequest(
            'msn_exec_1',
            'acknowledge',
            '2026-07-30T12:00:00Z',
            ['declaredPaths' => ['src/Domain/Mission/Mission.php']]
        ));

        Assert::true($result->isSucceeded());
        Assert::same(DeclarativeLocalExecutor::ID, $result->executorId());
        Assert::same(ExecutionResult::SUCCEEDED, $result->status());
        Assert::same('acknowledge', $result->context()['executedAction']);
        Assert::same('msn_exec_1', $result->context()['missionId']);
    }

    public function test_rejected(): void
    {
        $service = new ExecutionService(new DeclarativeLocalExecutor());
        $result = $service->execute(new ExecutionRequest(
            'msn_exec_2',
            'unsupported',
            '2026-07-30T12:00:00Z'
        ));

        Assert::true($result->isRejected());
        Assert::same(ExecutionResult::REJECTED, $result->status());
        Assert::true(str_contains($result->message(), 'unsupported'));
    }

    public function test_failed(): void
    {
        $service = new ExecutionService(new DeclarativeLocalExecutor());
        $result = $service->execute(new ExecutionRequest(
            'msn_exec_3',
            'simulate_fail',
            '2026-07-30T12:00:00Z'
        ));

        Assert::true($result->isFailed());
        Assert::same(ExecutionResult::FAILED, $result->status());
        Assert::true(str_contains($result->message(), 'simulated execution failure'));
    }

    public function test_invalid_request(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new ExecutionRequest('msn_exec_4', '', '2026-07-30T12:00:00Z');
        });
    }

    public function test_unexpected_executor_exception(): void
    {
        $service = new ExecutionService(new ThrowingExecutor());
        Assert::throws(\RuntimeException::class, static function () use ($service): void {
            $service->execute(new ExecutionRequest(
                'msn_exec_5',
                'anything',
                '2026-07-30T12:00:00Z'
            ));
        });
    }
}

final class ThrowingExecutor implements Executor
{
    public function id(): string
    {
        return 'throwing';
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        throw new \RuntimeException('simulated executor crash');
    }
}
