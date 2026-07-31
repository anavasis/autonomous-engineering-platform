<?php

declare(strict_types=1);

namespace Aep\Application\Planning\Policy;

final class SchedulingPolicySet
{
    /** @param list<SchedulingPolicy> $policies */
    public function __construct(private readonly array $policies)
    {
    }

    /** @return list<SchedulingPolicy> */
    public function all(): array
    {
        return $this->policies;
    }
}
