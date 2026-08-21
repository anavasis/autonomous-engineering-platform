<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

/**
 * Policy-driven merge readiness rule. Evaluator aggregates configured policies.
 */
interface PatchPolicy
{
    public function id(): string;

    /**
     * @return array{passed: bool, blocker: ?string, warning: ?string, message: string}
     */
    public function evaluate(Patch $patch): array;
}
