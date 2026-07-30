<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Persistence;

use Aep\Domain\Mission\Mission;
use Aep\Domain\Mission\MissionRepository;
use Aep\Domain\Mission\ValueObject\MissionId;

/**
 * In-memory MissionRepository. Sole ORCH-R1 persistence adapter.
 */
final class InMemoryMissionRepository implements MissionRepository
{
    /** @var array<string, Mission> */
    private array $missions = [];

    public function get(MissionId $id): Mission
    {
        $key = $id->toString();
        if (!isset($this->missions[$key])) {
            throw new \RuntimeException('Mission not found: ' . $key);
        }

        return $this->missions[$key];
    }

    public function save(Mission $mission): void
    {
        $this->missions[$mission->id()->toString()] = $mission;
    }

    public function exists(MissionId $id): bool
    {
        return isset($this->missions[$id->toString()]);
    }
}
