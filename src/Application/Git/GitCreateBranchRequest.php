<?php

declare(strict_types=1);

namespace Aep\Application\Git;

final class GitCreateBranchRequest extends GitProjectRequest
{
    public function __construct(
        string $projectId,
        string $occurredAtUtc,
        private string $branch,
        private bool $checkout = true
    ) {
        parent::__construct($projectId, $occurredAtUtc);
        $this->branch = self::req($branch, 'branch');
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function checkout(): bool
    {
        return $this->checkout;
    }
}
