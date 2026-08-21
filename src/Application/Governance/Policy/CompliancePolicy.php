<?php
declare(strict_types=1);
namespace Aep\Application\Governance\Policy;

use Aep\Application\Governance\Model\ReleaseRecord;

final class CompliancePolicy
{
    /**
     * @param array<string, mixed> $settings
     * @return array{pass: bool, violations: list<string>}
     */
    public function evaluate(ReleaseRecord $release, array $settings, bool $forProduction = false): array
    {
        $violations = [];
        if (($settings['requireGates'] ?? true) === true && !$release->allGatesPassed()) {
            $violations[] = 'quality gates incomplete';
        }
        if (($settings['requireApprovals'] ?? true) === true && !$release->approvalsSatisfied()) {
            $violations[] = 'approvals incomplete';
        }
        if (($settings['requireIntegrityHash'] ?? true) === true && $release->integrityHash() === '') {
            $violations[] = 'missing integrity hash';
        }
        if ($forProduction && ($settings['requireSealedArtifacts'] ?? false) === true) {
            if ($release->toArray()['artifactIds'] === []) {
                $violations[] = 'production requires artifact refs';
            }
        }
        if ($forProduction && count($release->patchIds()) === 0 && ($settings['requirePatchEvidence'] ?? true) === true) {
            $violations[] = 'production requires patch evidence';
        }
        return ['pass' => $violations === [], 'violations' => $violations];
    }
}
