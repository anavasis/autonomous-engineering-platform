<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Governance\Adapter;

use Aep\Application\Governance\Service\GovernanceObserveFacade;

/**
 * Thin adapter for observe hooks from HTTP/Kernel without changing Patch contracts.
 */
final class GovernanceObserveAdapter
{
    public function __construct(private readonly GovernanceObserveFacade $facade) {}

    /** @param array<string, mixed> $patchSummary */
    public function onPatchSealed(array $patchSummary, string $actorId = 'system'): void
    {
        $this->facade->onPatchSealed($patchSummary, $actorId);
    }

    /** @param array<string, mixed> $patchSummary */
    public function onPatchApproved(array $patchSummary, string $actorId = 'system'): void
    {
        // Observe-only: release candidates are created on seal, not approve.
        unset($patchSummary, $actorId);
    }
}
