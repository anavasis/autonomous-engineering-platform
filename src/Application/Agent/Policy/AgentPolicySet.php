<?php

declare(strict_types=1);

namespace Aep\Application\Agent\Policy;

final class AgentPolicySet
{
    /** @param list<AgentPolicy> $policies */
    public function __construct(private readonly array $policies) {}

    /** @return list<AgentPolicy> */
    public function all(): array { return $this->policies; }
}
