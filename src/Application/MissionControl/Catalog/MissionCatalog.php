<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Catalog;

use Aep\Domain\Mission\Mission;

/**
 * Read-only mission listing port (adapter; does not alter Domain repository).
 */
interface MissionCatalog
{
    /**
     * @return list<Mission>
     */
    public function all(): array;

    public function find(string $missionId): ?Mission;
}
