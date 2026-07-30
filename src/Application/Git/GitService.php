<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Application entry point for Git MVP operations.
 *
 * Delegates to {@see GitPort}. Does not invoke Mission, Validation, or Executor.
 */
final class GitService
{
    public function __construct(
        private readonly GitPort $git,
    ) {
    }

    public function clone(GitCloneRequest $request): GitResult
    {
        return $this->run(
            fn (): GitResult => $this->git->clone($request),
            'Clone failed.',
        );
    }

    public function fetch(GitRepoRequest $request): GitResult
    {
        return $this->run(
            fn (): GitResult => $this->git->fetch($request),
            'Fetch failed.',
        );
    }

    public function status(GitRepoRequest $request): GitResult
    {
        return $this->run(
            fn (): GitResult => $this->git->status($request),
            'Status failed.',
        );
    }

    public function currentBranch(GitRepoRequest $request): GitResult
    {
        return $this->run(
            fn (): GitResult => $this->git->currentBranch($request),
            'CurrentBranch failed.',
        );
    }

    public function checkout(GitCheckoutRequest $request): GitResult
    {
        return $this->run(
            fn (): GitResult => $this->git->checkout($request),
            'Checkout failed.',
        );
    }

    public function createBranch(GitCreateBranchRequest $request): GitResult
    {
        return $this->run(
            fn (): GitResult => $this->git->createBranch($request),
            'CreateBranch failed.',
        );
    }

    public function health(GitRepoRequest $request): GitHealth
    {
        try {
            return $this->git->health($request);
        } catch (\Throwable $e) {
            return new GitHealth(false, false, false, '', '', null);
        }
    }

    public function metadata(GitRepoRequest $request): GitMetadata
    {
        try {
            return $this->git->metadata($request);
        } catch (\Throwable $e) {
            return new GitMetadata('', '', '', null, 'unknown');
        }
    }

    public function operationLog(GitRepoRequest $request): GitOperationLog
    {
        try {
            return $this->git->operationLog($request);
        } catch (\Throwable $e) {
            return new GitOperationLog([]);
        }
    }

    /**
     * @param callable(): GitResult $operation
     */
    private function run(callable $operation, string $failureMessage): GitResult
    {
        try {
            return $operation();
        } catch (\InvalidArgumentException $e) {
            return GitResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return GitResult::failed($failureMessage . ' ' . $e->getMessage());
        }
    }
}
