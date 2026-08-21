<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl\Catalog;

use Aep\Application\MissionControl\Catalog\RunCatalog;
use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunRepository;
use Aep\Application\MissionEngine\MissionTimeline;

final class JsonRunCatalog implements RunCatalog
{
    public function __construct(
        private readonly MissionRunRepository $repository,
        private readonly string $directory,
    ) {
    }

    public function allCheckpoints(): array
    {
        $items = [];
        foreach ($this->runIds() as $runId) {
            try {
                $items[] = $this->repository->getCheckpoint($runId);
            } catch (\Throwable) {
                continue;
            }
        }

        return $items;
    }

    public function checkpointsForMission(string $missionId): array
    {
        $items = [];
        foreach ($this->allCheckpoints() as $checkpoint) {
            if ($checkpoint->missionId() === $missionId) {
                $items[] = $checkpoint;
            }
        }

        return $items;
    }

    public function findCheckpoint(string $runId): ?MissionCheckpoint
    {
        if (!$this->repository->exists($runId)) {
            return null;
        }

        return $this->repository->getCheckpoint($runId);
    }

    public function findTimeline(string $runId): ?MissionTimeline
    {
        if (!$this->repository->exists($runId)) {
            return null;
        }

        return $this->repository->getTimeline($runId);
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $ids = [];
        foreach (scandir($this->directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && is_file($path . DIRECTORY_SEPARATOR . 'checkpoint.json')) {
                $ids[] = $entry;
            }
        }
        sort($ids);

        return $ids;
    }
}
