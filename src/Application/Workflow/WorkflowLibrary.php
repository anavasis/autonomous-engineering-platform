<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Resolves workflow documents by id and version.
 */
interface WorkflowLibrary
{
    public function get(string $workflowId, ?string $version = null): WorkflowDefinition;

    public function exists(string $workflowId, ?string $version = null): bool;
}
