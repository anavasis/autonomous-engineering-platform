<?php

declare(strict_types=1);

namespace Aep\Application\Acceptance\Port;

use Aep\Application\Acceptance\Model\AcceptanceProject;

interface AcceptanceProjectRepository
{
    public function get(string $name): ?AcceptanceProject;

    /** @return list<AcceptanceProject> */
    public function all(): array;

    /** Absolute directory for a project (for reference workspace resolution). */
    public function projectDirectory(string $name): ?string;
}
