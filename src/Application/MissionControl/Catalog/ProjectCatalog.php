<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Catalog;

use Aep\Domain\Project\Project;

interface ProjectCatalog
{
    /**
     * @return list<Project>
     */
    public function all(): array;

    public function find(string $projectId): ?Project;
}
