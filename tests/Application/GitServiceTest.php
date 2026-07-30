<?php

declare(strict_types=1);

namespace Tests\Application;

use Aep\Application\Git\GitCheckoutRequest;
use Aep\Application\Git\GitCloneRequest;
use Aep\Application\Git\GitCreateBranchRequest;
use Aep\Application\Git\GitHealth;
use Aep\Application\Git\GitMetadata;
use Aep\Application\Git\GitOperationLog;
use Aep\Application\Git\GitOperationLogEntry;
use Aep\Application\Git\GitPort;
use Aep\Application\Git\GitRepoRequest;
use Aep\Application\Git\GitResult;
use Aep\Application\Git\GitService;
use Tests\Support\Assert;

/**
 * Application GitService orchestration tests (fake GitPort).
 */
final class GitServiceTest
{
    public function test_clone_success(): void
    {
        $port = new GitServiceFakePort();
        $port->cloneResult = GitResult::succeeded('ok', ['branch' => 'main']);
        $service = new GitService($port);

        $result = $service->clone(new GitCloneRequest(
            'proj_1',
            '2026-07-30T12:00:00Z',
            'local',
            'file:///tmp/example.git',
        ));

        Assert::true($result->isSucceeded());
        Assert::same('ok', $result->message());
        Assert::same(1, $port->cloneCalls);
    }

    public function test_clone_failure_from_port(): void
    {
        $port = new GitServiceFakePort();
        $port->cloneResult = GitResult::failed('remote unreachable');
        $service = new GitService($port);

        $result = $service->clone(new GitCloneRequest(
            'proj_1',
            '2026-07-30T12:00:00Z',
            'local',
            'file:///tmp/missing.git',
        ));

        Assert::true($result->isFailed());
        Assert::same('remote unreachable', $result->message());
    }

    public function test_clone_port_exception_becomes_failed(): void
    {
        $port = new GitServiceFakePort();
        $port->throwOnClone = new \RuntimeException('disk full');
        $service = new GitService($port);

        $result = $service->clone(new GitCloneRequest(
            'proj_1',
            '2026-07-30T12:00:00Z',
            'local',
            'file:///tmp/example.git',
        ));

        Assert::true($result->isFailed());
        Assert::true(str_contains($result->message(), 'Clone failed.'));
        Assert::true(str_contains($result->message(), 'disk full'));
    }

    public function test_invalid_request_is_rejected(): void
    {
        $port = new GitServiceFakePort();
        $port->throwOnClone = new \InvalidArgumentException('repository must be non-empty.');
        $service = new GitService($port);

        $result = $service->clone(new GitCloneRequest(
            'proj_1',
            '2026-07-30T12:00:00Z',
            'local',
            'file:///tmp/example.git',
        ));

        Assert::true($result->isRejected());
        Assert::same('repository must be non-empty.', $result->message());
    }

    public function test_invalid_project_id_rejected_by_request(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new GitCloneRequest(
                'bad/id',
                '2026-07-30T12:00:00Z',
                'local',
                'file:///tmp/example.git',
            );
        });
    }

    public function test_clone_request_rejects_embedded_credentials(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new GitCloneRequest(
                'proj_1',
                '2026-07-30T12:00:00Z',
                'github',
                'https://user:token@github.com/org/repo.git',
            );
        });
    }

    public function test_fetch_checkout_create_branch_delegate(): void
    {
        $port = new GitServiceFakePort();
        $port->fetchResult = GitResult::succeeded('fetched');
        $port->checkoutResult = GitResult::succeeded('checked out');
        $port->createBranchResult = GitResult::succeeded('created');
        $service = new GitService($port);

        Assert::true($service->fetch(new GitRepoRequest('proj_1', '2026-07-30T12:00:00Z'))->isSucceeded());
        Assert::true($service->checkout(new GitCheckoutRequest('proj_1', '2026-07-30T12:00:00Z', 'main'))->isSucceeded());
        Assert::true($service->createBranch(new GitCreateBranchRequest('proj_1', '2026-07-30T12:00:00Z', 'feature'))->isSucceeded());
        Assert::same(1, $port->fetchCalls);
        Assert::same(1, $port->checkoutCalls);
        Assert::same(1, $port->createBranchCalls);
    }

    public function test_health_metadata_operation_log(): void
    {
        $port = new GitServiceFakePort();
        $service = new GitService($port);
        $req = new GitRepoRequest('proj_1', '2026-07-30T12:00:00Z');

        $health = $service->health($req);
        Assert::true($health->connected());
        Assert::same('main', $health->currentBranch());

        $meta = $service->metadata($req);
        Assert::same('main', $meta->currentBranch());
        Assert::same('healthy', $meta->repositoryHealth());

        $log = $service->operationLog($req);
        Assert::same(1, count($log->entries()));
        Assert::same('Clone', $log->entries()[0]->operation());
    }
}

/**
 * In-file fake port for GitServiceTest (loaded with the test file).
 */
final class GitServiceFakePort implements GitPort
{
    public int $cloneCalls = 0;
    public int $fetchCalls = 0;
    public int $checkoutCalls = 0;
    public int $createBranchCalls = 0;

    public GitResult $cloneResult;
    public GitResult $fetchResult;
    public GitResult $statusResult;
    public GitResult $currentBranchResult;
    public GitResult $checkoutResult;
    public GitResult $createBranchResult;

    public ?\Throwable $throwOnClone = null;

    public function __construct()
    {
        $this->cloneResult = GitResult::succeeded('cloned');
        $this->fetchResult = GitResult::succeeded('fetched');
        $this->statusResult = GitResult::succeeded('clean', ['clean' => true]);
        $this->currentBranchResult = GitResult::succeeded('branch', ['branch' => 'main']);
        $this->checkoutResult = GitResult::succeeded('checked out');
        $this->createBranchResult = GitResult::succeeded('created');
    }

    public function clone(GitCloneRequest $request): GitResult
    {
        $this->cloneCalls++;
        if ($this->throwOnClone !== null) {
            throw $this->throwOnClone;
        }

        return $this->cloneResult;
    }

    public function fetch(GitRepoRequest $request): GitResult
    {
        $this->fetchCalls++;

        return $this->fetchResult;
    }

    public function status(GitRepoRequest $request): GitResult
    {
        return $this->statusResult;
    }

    public function currentBranch(GitRepoRequest $request): GitResult
    {
        return $this->currentBranchResult;
    }

    public function checkout(GitCheckoutRequest $request): GitResult
    {
        $this->checkoutCalls++;

        return $this->checkoutResult;
    }

    public function createBranch(GitCreateBranchRequest $request): GitResult
    {
        $this->createBranchCalls++;

        return $this->createBranchResult;
    }

    public function health(GitRepoRequest $request): GitHealth
    {
        return new GitHealth(true, true, true, 'main', 'abc123', '2026-07-30T12:00:00Z');
    }

    public function metadata(GitRepoRequest $request): GitMetadata
    {
        return new GitMetadata('main', 'abc123', 'file:///tmp/example.git', '2026-07-30T12:00:00Z', 'healthy');
    }

    public function operationLog(GitRepoRequest $request): GitOperationLog
    {
        return new GitOperationLog([
            new GitOperationLogEntry('2026-07-30T12:00:00Z', 'Clone', 'succeeded', 'ok'),
        ]);
    }
}
