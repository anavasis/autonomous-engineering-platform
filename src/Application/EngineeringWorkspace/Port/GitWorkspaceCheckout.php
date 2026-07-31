<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringWorkspace\Port;

use Aep\Application\EngineeringWorkspace\Model\EngineeringWorkspace;

/**
 * Materializes a project checkout into a workspace without changing Git MVP semantics.
 */
interface GitWorkspaceCheckout
{
    /**
     * @param array{provider?: string, repository?: string, baseBranch?: string, secretVault?: ?string, secretKey?: ?string} $spec
     * @return array<string, mixed> git metadata written onto the workspace
     */
    public function materialize(EngineeringWorkspace $workspace, array $spec): array;
}
