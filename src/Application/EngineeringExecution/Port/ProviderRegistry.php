<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Port;

/**
 * Configuration-driven provider discovery. Orchestrator depends only on this registry.
 */
interface ProviderRegistry
{
    public function has(string $providerId): bool;

    public function get(string $providerId): EngineeringExecutionProvider;

    /**
     * @return list<EngineeringExecutionProvider>
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function ids(): array;
}
