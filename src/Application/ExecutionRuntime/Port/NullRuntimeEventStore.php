<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Port;

use Aep\Application\ExecutionRuntime\Model\RuntimeEvent;

/** No-op event store for tests / optional wiring. */
final class NullRuntimeEventStore implements RuntimeEventStore
{
    public function append(RuntimeEvent $event): void
    {
    }

    public function forJob(string $jobId): array
    {
        return [];
    }
}
