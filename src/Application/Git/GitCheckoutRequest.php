<?php

declare(strict_types=1);

namespace Aep\Application\Git;

final class GitCheckoutRequest extends GitProjectRequest
{
    public function __construct(
        string $projectId,
        string $occurredAtUtc,
        private string $branch
    ) {
        parent::__construct($projectId, $occurredAtUtc);
        $this->branch = self::req($branch, 'branch');
    }

    public function branch(): string
    {
        return $this->branch;
    }
}
