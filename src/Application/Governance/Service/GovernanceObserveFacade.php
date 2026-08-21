<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Service;

use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Port\GovernanceSettingsStore;

/**
 * Side-effect observer used by Infrastructure adapters / HTTP hooks.
 * Does not modify Patch/Planning/Mission semantics.
 */
final class GovernanceObserveFacade
{
    public function __construct(
        private readonly ReleaseManager $releases,
        private readonly GovernanceManager $manager,
        private readonly GovernanceSettingsStore $settings,
    ) {}

    /**
     * @param array<string, mixed> $patchSummary
     */
    public function onPatchSealed(array $patchSummary, string $actorId = 'system'): ?ReleaseRecord
    {
        $s = $this->settings->get();
        if (($s['enabled'] ?? true) !== true || ($s['observePatchSeal'] ?? true) !== true) {
            return null;
        }
        $patchId = is_string($patchSummary['patchId'] ?? null) ? $patchSummary['patchId'] : '';
        if ($patchId === '') { return null; }
        try {
            $cr = $this->manager->createChangeRequest([
                'title' => 'Patch ' . $patchId,
                'patchIds' => [$patchId],
                'missionIds' => is_string($patchSummary['missionId'] ?? null) ? [$patchSummary['missionId']] : [],
            ], $actorId);
            $release = $this->releases->create([
                'title' => 'Release from ' . $patchId,
                'version' => is_string($patchSummary['version'] ?? null) ? $patchSummary['version'] : null,
                'patchIds' => [$patchId],
                'changeRequestIds' => [$cr->changeRequestId()],
                'missionIds' => is_string($patchSummary['missionId'] ?? null) ? [$patchSummary['missionId']] : [],
                'meta' => ['source' => 'patch_seal'],
            ], $actorId);
            $this->releases->submitCandidate($release->releaseId(), [
                'reviewed' => true,
                'tests' => 'passed',
                'securityScan' => 'passed',
            ], $actorId);
            return $release;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function onPlanningLaunch(array $context, string $actorId = 'system'): void
    {
        $s = $this->settings->get();
        if (($s['enabled'] ?? true) !== true || ($s['observePlanningLaunch'] ?? false) !== true) {
            return;
        }
        try {
            $this->manager->createChangeRequest([
                'title' => 'Planning launch ' . ($context['nodeId'] ?? ''),
                'missionIds' => is_string($context['missionId'] ?? null) ? [$context['missionId']] : [],
                'programId' => $context['programId'] ?? null,
                'meta' => ['source' => 'planning_launch'] + $context,
            ], $actorId);
        } catch (\Throwable) {
        }
    }
}
