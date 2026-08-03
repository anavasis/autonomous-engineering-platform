<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Port;

use Aep\Application\ExecutionRuntime\Model\RuntimeEvent;

/**
 * Append-only store for Runtime-level job events.
 */
interface RuntimeEventStore
{
    public function append(RuntimeEvent $event): void;

    /** @return list<RuntimeEvent> */
    public function forJob(string $jobId): array;
}
