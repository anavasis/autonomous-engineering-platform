<?php

declare(strict_types=1);

namespace Aep\Domain\Project;

use Aep\Domain\Project\ValueObject\ProjectId;

interface ProjectRepository
{
    public function get(ProjectId $id): Project;

    public function save(Project $project): void;

    public function exists(ProjectId $id): bool;
}
