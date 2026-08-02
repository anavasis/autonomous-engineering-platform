<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

/** Collection helper for deployment attempts. */
final class DeploymentHistory
{
    /** @param list<DeploymentPlan> $items */
    public function __construct(private array $items = []) {}
    /** @return list<DeploymentPlan> */
    public function items(): array { return $this->items; }
    /** @return list<array<string, mixed>> */
    public function toArray(): array { return array_map(static fn (DeploymentPlan $d) => $d->toArray(), $this->items); }
}
