<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Policy;

use Aep\Application\Governance\Model\DeploymentPlan;
use Aep\Application\Governance\Model\ReleaseRecord;

final class RollbackPolicy
{
    /**
     * @param list<DeploymentPlan> $history
     * @return array{admit: bool, reason: string}
     */
    public function canRollback(ReleaseRecord $current, ?ReleaseRecord $prior, array $history): array
    {
        if ($prior === null) {
            return ['admit' => false, 'reason' => 'no prior release'];
        }
        $hasDeploy = false;
        foreach ($history as $d) {
            if ($d->releaseId() === $current->releaseId() && $d->status() === DeploymentPlan::STATUS_FINISHED) {
                $hasDeploy = true;
                break;
            }
        }
        if (!$hasDeploy && $current->status() !== ReleaseRecord::STATUS_DEPLOYED) {
            return ['admit' => false, 'reason' => 'current release has no finished deployment'];
        }
        return ['admit' => true, 'reason' => 'rollback allowed'];
    }
}
