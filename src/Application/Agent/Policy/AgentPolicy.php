<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Policy;

use Aep\Application\Agent\Model\Agent;

interface AgentPolicy
{
    public function id(): string;

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $context
     * @return array{admit: bool, scoreDelta: float, reason: string}
     */
    public function evaluate(array $request, Agent $candidate, array $context = []): array;
}
