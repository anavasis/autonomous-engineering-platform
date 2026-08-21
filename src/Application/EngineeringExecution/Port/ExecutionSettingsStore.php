<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Port;

interface ExecutionSettingsStore
{
    /** @return array<string, mixed> */
    public function get(): array;

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(array $patch): array;
}
