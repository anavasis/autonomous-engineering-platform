<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Model;

/** Snapshot of governance counters persisted in GovernanceStore. */
final class GovernanceMetrics
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = []) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'releasesCreated' => (int) ($this->data['releasesCreated'] ?? 0),
            'deploymentsFinished' => (int) ($this->data['deploymentsFinished'] ?? 0),
            'rollbacksCompleted' => (int) ($this->data['rollbacksCompleted'] ?? 0),
            'updatedAtUtc' => is_string($this->data['updatedAtUtc'] ?? null) ? $this->data['updatedAtUtc'] : null,
        ] + $this->data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }
}
