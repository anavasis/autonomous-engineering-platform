<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\MergeReadiness;
use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\CodeReview\Policy\PatchPolicySet;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Evaluates configured PatchPolicy rules — no hardcoded merge conditions.
 */
final class MergeReadinessEvaluator
{
    public function __construct(
        private readonly PatchPolicySet $policies,
    ) {
    }

    public function evaluate(Patch $patch): MergeReadiness
    {
        $checklist = [];
        $blockers = [];
        $warnings = [];

        foreach ($this->policies->all() as $policy) {
            $result = $policy->evaluate($patch);
            $checklist[] = [
                'rule' => $policy->id(),
                'passed' => ($result['passed'] ?? false) === true,
                'message' => is_string($result['message'] ?? null) ? $result['message'] : '',
            ];
            if (($result['passed'] ?? false) !== true && is_string($result['blocker'] ?? null) && $result['blocker'] !== '') {
                $blockers[] = $result['blocker'];
            }
            if (is_string($result['warning'] ?? null) && $result['warning'] !== '') {
                $warnings[] = $result['warning'];
            }
        }

        return new MergeReadiness(
            $blockers === [],
            $blockers,
            $warnings,
            $checklist,
            Utc::now(),
        );
    }
}
