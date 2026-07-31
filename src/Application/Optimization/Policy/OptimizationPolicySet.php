<?php
declare(strict_types=1);
namespace Aep\Application\Optimization\Policy;

final class OptimizationPolicySet
{
    /** @param list<OptimizationPolicy> $policies */
    public function __construct(private array $policies) {}
    /** @return list<OptimizationPolicy> */
    public function all(): array { return $this->policies; }
}
