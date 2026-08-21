<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

/** Timeline collection for GovernanceEvent records. */
final class GovernanceEvents
{
    /** @param list<GovernanceEvent> $events */
    public function __construct(private array $events = []) {}

    /** @return list<GovernanceEvent> */
    public function items(): array { return $this->events; }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (GovernanceEvent $e) => $e->toArray(), $this->events);
    }
}
