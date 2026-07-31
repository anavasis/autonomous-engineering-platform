<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl\Catalog;

use Aep\Application\MissionControl\Catalog\ProjectCatalog;
use Aep\Domain\Project\Project;
use Aep\Domain\Project\ProjectRepository;
use Aep\Domain\Project\ValueObject\ProjectId;

final class JsonProjectCatalog implements ProjectCatalog
{
    public function __construct(
        private readonly ProjectRepository $repository,
        private readonly string $directory,
    ) {
    }

    public function all(): array
    {
        $projects = [];
        foreach ($this->ids() as $id) {
            try {
                $projects[] = $this->repository->get(new ProjectId($id));
            } catch (\Throwable) {
                continue;
            }
        }

        return $projects;
    }

    public function find(string $projectId): ?Project
    {
        $id = new ProjectId($projectId);
        if (!$this->repository->exists($id)) {
            return null;
        }

        return $this->repository->get($id);
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $ids = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $base = basename($file, '.json');
            if ($base !== '') {
                $ids[] = $base;
            }
        }
        sort($ids);

        return $ids;
    }
}
