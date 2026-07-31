<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Point-in-time Git health snapshot for a project workspace.
 */
final class GitHealth
{
    public function __construct(
        private bool $connected,
        private bool $remoteReachable,
        private bool $workingTreeClean,
        private string $currentBranch,
        private string $currentCommit,
        private ?string $lastFetchAt
    ) {
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function remoteReachable(): bool
    {
        return $this->remoteReachable;
    }

    public function workingTreeClean(): bool
    {
        return $this->workingTreeClean;
    }

    public function currentBranch(): string
    {
        return $this->currentBranch;
    }

    public function currentCommit(): string
    {
        return $this->currentCommit;
    }

    public function lastFetchAt(): ?string
    {
        return $this->lastFetchAt;
    }
}
