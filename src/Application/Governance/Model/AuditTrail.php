<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

/** Ordered audit trail view over AuditEntry records. */
final class AuditTrail
{
    /** @param list<AuditEntry> $entries */
    public function __construct(private array $entries = []) {}

    /** @return list<AuditEntry> */
    public function entries(): array { return $this->entries; }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (AuditEntry $e) => $e->toArray(), $this->entries);
    }
}
