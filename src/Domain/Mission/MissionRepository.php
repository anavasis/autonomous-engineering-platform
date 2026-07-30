<?php

declare(strict_types=1);

namespace Aep\Domain\Mission;

use Aep\Domain\Mission\ValueObject\MissionId;

/**
 * Persistence port for the Mission aggregate. No storage technology assumptions.
 */
interface MissionRepository
{
    public function get(MissionId $id): Mission;

    public function save(Mission $mission): void;

    public function exists(MissionId $id): bool;
}
