<?php

declare(strict_types=1);

namespace Aep\Infrastructure\MissionControl\Catalog;

use Aep\Application\MissionControl\Catalog\MissionCatalog;
use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\MissionRepository;
use Aep\Domain\Mission\ValueObject\MissionId;

/**
 * Directory-scanning read adapter over JsonFileMissionRepository storage.
 */
final class JsonMissionCatalog implements MissionCatalog
{
    public function __construct(
        private readonly MissionRepository $repository,
        private readonly string $directory,
    ) {
    }

    public function all(): array
    {
        $missions = [];
        foreach ($this->ids() as $id) {
            try {
                $missions[] = $this->repository->get(new MissionId($id));
            } catch (\Throwable) {
                continue;
            }
        }

        return $missions;
    }

    public function find(string $missionId): ?Mission
    {
        $id = new MissionId($missionId);
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
            if ($base !== '' && !str_contains($base, '.')) {
                $ids[] = $base;
            }
        }
        sort($ids);

        return $ids;
    }
}
