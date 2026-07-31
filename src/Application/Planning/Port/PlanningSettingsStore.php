<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Port;

interface PlanningSettingsStore
{
    /** @return array<string, mixed> */
    public function get(): array;

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function put(array $settings): array;
}
