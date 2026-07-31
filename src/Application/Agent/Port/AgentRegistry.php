<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Port;

use Aep\Application\Agent\Model\Agent;

interface AgentRegistry
{
    public function get(string $agentId): ?Agent;

    /** @return list<Agent> */
    public function candidates(?string $role = null, array $capabilities = [], bool $routableOnly = true): array;

    /** @return list<array<string, mixed>> */
    public function list(): array;
}
