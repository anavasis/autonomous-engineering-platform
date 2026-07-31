<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

final class PatchPolicySet
{
    /** @param list<PatchPolicy> $policies */
    public function __construct(
        private readonly array $policies,
    ) {
        foreach ($this->policies as $policy) {
            if (!$policy instanceof PatchPolicy) {
                throw new \InvalidArgumentException('PatchPolicySet requires PatchPolicy instances.');
            }
        }
    }

    /** @return list<PatchPolicy> */
    public function all(): array
    {
        return $this->policies;
    }
}
