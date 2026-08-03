<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Port;

use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Application\ExecutionRuntime\Service\JobExecutionContext;

/**
 * Pluggable handler for a Runtime job type.
 * New job types register a handler — Runtime core stays unchanged.
 */
interface JobHandler
{
    public function type(): string;

    /**
     * @return array<string, mixed> result payload stored on completion
     */
    public function execute(RuntimeJob $job, JobExecutionContext $context): array;
}
