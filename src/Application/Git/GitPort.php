<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Application port for Git MVP operations.
 */
interface GitPort
{
    public function clone(GitCloneRequest $request): GitResult;

    public function fetch(GitRepoRequest $request): GitResult;

    public function status(GitRepoRequest $request): GitResult;

    public function currentBranch(GitRepoRequest $request): GitResult;

    public function checkout(GitCheckoutRequest $request): GitResult;

    public function createBranch(GitCreateBranchRequest $request): GitResult;

    public function health(GitRepoRequest $request): GitHealth;

    public function metadata(GitRepoRequest $request): GitMetadata;

    public function operationLog(GitRepoRequest $request): GitOperationLog;
}
