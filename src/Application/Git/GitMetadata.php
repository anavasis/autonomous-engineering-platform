<?php

declare(strict_types=1);

namespace Aep\Application\Git;

/**
 * Cache-only workspace metadata view (not Domain state).
 */
final class GitMetadata
{
    public function __construct(
        private string $currentBranch,
        private string $currentCommitSha,
        private string $remoteUrl,
        private ?string $lastFetchAt,
        private string $repositoryHealth
    ) {
    }

    public function currentBranch(): string
    {
        return $this->currentBranch;
    }

    public function currentCommitSha(): string
    {
        return $this->currentCommitSha;
    }

    public function remoteUrl(): string
    {
        return $this->remoteUrl;
    }

    public function lastFetchAt(): ?string
    {
        return $this->lastFetchAt;
    }

    public function repositoryHealth(): string
    {
        return $this->repositoryHealth;
    }
}
