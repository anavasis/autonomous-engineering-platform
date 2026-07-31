<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Persistence;

use Aep\Domain\Project\Project;
use Aep\Domain\Project\ProjectRepository;
use Aep\Domain\Project\ValueObject\ProjectId;

final class InMemoryProjectRepository implements ProjectRepository
{
    /** @var array<string, Project> */
    private array $projects = [];

    public function get(ProjectId $id): Project
    {
        $key = $id->toString();
        if (!isset($this->projects[$key])) {
            throw new \RuntimeException('Project not found: ' . $key);
        }

        return $this->projects[$key];
    }

    public function save(Project $project): void
    {
        $this->projects[$project->id()->toString()] = $project;
    }

    public function exists(ProjectId $id): bool
    {
        return isset($this->projects[$id->toString()]);
    }
}
