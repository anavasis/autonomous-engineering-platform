<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

final class PatchPolicyFactory
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): PatchPolicySet
    {
        $minScore = is_int($settings['minScore'] ?? null) ? $settings['minScore'] : 70;
        $mode = is_string($settings['reviewMode'] ?? null) ? $settings['reviewMode'] : 'any';
        $quorum = is_int($settings['reviewQuorum'] ?? null) ? $settings['reviewQuorum'] : 1;
        $requireHuman = ($settings['requireHumanApproval'] ?? false) === true;
        $kinds = ['diff', 'static', 'tests'];
        if (isset($settings['requiredCheckKinds']) && is_array($settings['requiredCheckKinds'])) {
            $kinds = [];
            foreach ($settings['requiredCheckKinds'] as $k) {
                if (is_string($k)) {
                    $kinds[] = $k;
                }
            }
            if ($kinds === []) {
                $kinds = ['diff', 'static', 'tests'];
            }
        }

        return new PatchPolicySet([
            new RequireManifestIntegrityPolicy(),
            new RequireNoConflictPolicy(),
            new RequireChecksPassedPolicy($kinds),
            new RequireScorePolicy($minScore),
            new RequireReviewConsensusPolicy($mode, $quorum, $requireHuman),
            new RequireStatusPolicy(),
        ]);
    }
}
