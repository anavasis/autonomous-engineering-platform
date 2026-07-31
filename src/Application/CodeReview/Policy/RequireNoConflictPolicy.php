<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Policy;

use Aep\Application\CodeReview\Model\Patch;

final class RequireNoConflictPolicy implements PatchPolicy
{
    public function id(): string
    {
        return 'require_no_conflict';
    }

    public function evaluate(Patch $patch): array
    {
        $conflicted = in_array($patch->status(), [Patch::STATUS_CONFLICTED, Patch::STATUS_STALE], true);

        return [
            'passed' => !$conflicted,
            'blocker' => $conflicted ? 'Patch is ' . $patch->status() : null,
            'warning' => null,
            'message' => $conflicted ? 'Resolve conflicts or regenerate patch' : 'No conflicts detected',
        ];
    }
}
